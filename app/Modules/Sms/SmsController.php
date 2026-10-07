<?php

declare(strict_types=1);

namespace App\Modules\Sms;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Imports\Importer;
use App\Services\Sms\Gateways;
use DateTimeImmutable;
use RuntimeException;

/** SMS: history, single send, bulk campaigns, templates. Default gateway is the test gateway. */
final class SmsController
{
    public const STATUSES = ['queued' => 'Queued', 'pending' => 'Sending', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
    public const CATEGORIES = ['transactional' => 'Transactional', 'service' => 'Service', 'promotional' => 'Promotional', 'otp' => 'OTP'];
    private const PER_PAGE = 50;
    private const INLINE_LIMIT = 500;     // larger campaigns finish via cli/sms-dispatch.php

    // ---- history -----------------------------------------------------------------

    public static function index(): void
    {
        $user = Auth::user();
        $f = [
            'status'   => array_key_exists($_GET['status'] ?? '', self::STATUSES) ? $_GET['status'] : '',
            'from'     => Importer::parseDate((string) ($_GET['from'] ?? '')) ?? '',
            'to'       => Importer::parseDate((string) ($_GET['to'] ?? '')) ?? '',
            'q'        => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60),
            'campaign' => (int) ($_GET['campaign'] ?? 0) ?: null,
        ];
        [$w, $p] = self::visibility($user);
        foreach (['status' => 's.status = ?', 'from' => 's.created_at >= ?', 'to' => 's.created_at < DATE_ADD(?, INTERVAL 1 DAY)', 'campaign' => 's.campaign_id = ?'] as $k => $cond) {
            if ($f[$k] !== '' && $f[$k] !== null) {
                $w .= " AND {$cond}";
                $p[] = $k === 'from' ? $f[$k] . ' 00:00:00' : $f[$k];
            }
        }
        if ($f['q'] !== '') {
            $w .= ' AND (s.recipient_mobile LIKE ? OR s.recipient_name LIKE ? OR s.message LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM sms_messages s WHERE {$w}", $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        [$vw, $vp] = self::visibility($user);
        $today = date('Y-m-d');
        $stats = Database::fetch(
            "SELECT SUM(DATE(s.created_at) = ? AND s.status IN ('sent','delivered')) AS sent_today,
                    SUM(DATE(s.created_at) = ? AND s.status = 'failed') AS failed_today,
                    SUM(s.status IN ('queued','pending')) AS waiting,
                    SUM(s.created_at >= ? AND s.status IN ('sent','delivered')) AS sent_month,
                    COALESCE(SUM(IF(s.created_at >= ? AND s.status IN ('sent','delivered'), s.segments, 0)), 0) AS segments_month
             FROM sms_messages s WHERE {$vw}",
            array_merge([$today, $today, date('Y-m-01'), date('Y-m-01')], $vp)
        );

        Response::view('sms/index', [
            'title'    => 'SMS',
            'flash'    => Session::takeFlash(),
            'filters'  => $f,
            'stats'    => $stats,
            'messages' => Database::fetchAll(
                "SELECT s.*, c.name AS campaign_name, u.name AS user_name FROM sms_messages s
                 LEFT JOIN sms_campaigns c ON c.id = s.campaign_id LEFT JOIN users u ON u.id = s.created_by
                 WHERE {$w} ORDER BY s.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
                $p
            ),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'testMode' => self::testMode(),
            'can'      => self::abilities(),
        ]);
    }

    public static function message(array $p): void
    {
        [$w, $params] = self::visibility(Auth::user());
        $m = Database::fetch("SELECT s.*, c.name AS campaign_name, t.name AS template_name FROM sms_messages s
                              LEFT JOIN sms_campaigns c ON c.id = s.campaign_id LEFT JOIN sms_templates t ON t.id = s.template_id
                              WHERE s.id = ? AND {$w}", array_merge([(int) ($p['id'] ?? 0)], $params));
        if ($m === null) {
            Response::error(404, 'Message not found.');
            return;
        }
        Response::view('sms/message', [
            'title' => 'SMS to ' . $m['recipient_mobile'],
            'm'     => $m,
            'logs'  => Database::fetchAll('SELECT * FROM sms_logs WHERE sms_message_id = ? ORDER BY id', [$m['id']]),
        ]);
    }

    // ---- single send ------------------------------------------------------------

    public static function compose(): void
    {
        $values = Session::pull('_old', []);
        // Opened from an enquiry, an order or a payment statement (Screen 4)
        $for = (string) ($_GET['for'] ?? ($values['for'] ?? ''));
        $ctx = $for !== '' ? SmsContext::load($for, (int) ($_GET['id'] ?? ($values['ref_id'] ?? 0)), Auth::user()) : null;
        if ($ctx !== null) {
            $values += ['for' => $ctx['for'], 'ref_id' => $ctx['id'], 'customer_code' => $ctx['customer_code'] ?? '',
                        'mobile' => $ctx['customer_code'] ? '' : ($ctx['mobile'] ?? ''), 'name' => $ctx['name'] ?? ''];
        }
        $tid = (int) ($_GET['template'] ?? ($values['template_id'] ?? 0));
        if ($tid === 0 && $ctx !== null) {
            $tid = (int) Database::value("SELECT id FROM sms_templates WHERE name = ? AND status = 'active' AND deleted_at IS NULL", [$ctx['template']]);
        }
        $template = $tid ? Database::fetch("SELECT * FROM sms_templates WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$tid]) : null;
        if ($template && !isset($values['message'])) {
            $values['message'] = $template['body'];
            $values['template_id'] = $template['id'];
        }
        foreach (['customer_code', 'mobile'] as $k) {
            if (isset($_GET[$k]) && !isset($values[$k])) {
                $values[$k] = mb_substr((string) $_GET[$k], 0, 30);
            }
        }
        Response::view('sms/send', [
            'title'     => 'Send SMS',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_errors', []),
            'values'    => $values,
            'preview'   => Session::pull('_preview'),
            'templates' => self::templates(),
            'testMode'  => self::testMode(),
            'context'   => $ctx,
        ]);
    }

    public static function send(): void
    {
        $user = Auth::user();
        $in = [
            'customer_code' => strtoupper(Request::input('customer_code', 30)),
            'mobile'        => Request::input('mobile', 20),
            'name'          => Request::input('name', 150),
            'template_id'   => (int) Request::input('template_id', 10) ?: null,
            'message'       => trim((string) ($_POST['message'] ?? '')),
        ];
        $errors = [];
        $customer = null;
        $ctx = null;
        if (($_POST['for'] ?? '') !== '') {
            $ctx = SmsContext::load((string) $_POST['for'], (int) ($_POST['ref_id'] ?? 0), $user);
            if ($ctx === null) {
                $errors['message'] = 'The record this SMS was opened from was not found.';
            }
        }
        if ($in['customer_code'] !== '') {
            $customer = Database::fetch(
                "SELECT c.*, e.name AS employee_name FROM customers c LEFT JOIN employees e ON e.id = c.employee_id
                 WHERE c.customer_code = ? AND c.deleted_at IS NULL", [$in['customer_code']]);
            $scope = DataScope::for($user);
            if ($customer === null || !$scope->allowsBranch((int) $customer['branch_id']) || !$scope->allowsEmployee($customer['employee_id'] !== null ? (int) $customer['employee_id'] : null)) {
                $errors['customer_code'] = "Customer {$in['customer_code']} was not found in your customers.";
                $customer = null;
            }
        }
        $mobile = SmsText::mobile($in['mobile'] !== '' ? $in['mobile'] : ($customer['mobile'] ?? null));
        if ($mobile === null) {
            $errors['mobile'] = $in['mobile'] === '' && $customer === null ? 'Enter a mobile number or a customer code.' : 'Enter a valid 10-digit mobile number.';
        }
        $template = $in['template_id'] ? Database::fetch("SELECT * FROM sms_templates WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$in['template_id']]) : null;
        $category = $template['category'] ?? 'transactional';
        if ($customer !== null && (int) $customer['sms_opt_out'] === 1 && in_array($category, ['promotional', 'service'], true)) {
            $errors['customer_code'] = "{$customer['name']} has opted out of SMS; only transactional messages can be sent.";
        }
        if ($in['message'] === '') {
            $errors['message'] = 'Write the message or choose a template.';
        } elseif ($unknown = SmsText::unknownPlaceholders($in['message'])) {
            $errors['message'] = 'Unknown placeholder(s): {' . implode('}, {', $unknown) . '}.';
        }

        $text = '';
        if (!$errors) {
            $outstanding = $customer ? Database::fetch(
                "SELECT SUM(balance) AS amount, SUBSTRING_INDEX(GROUP_CONCAT(invoice_no ORDER BY invoice_date, invoice_id), ',', 1) AS first_invoice
                 FROM v_invoice_balances WHERE customer_id = ? AND balance > 0 AND due_date < CURDATE()", [$customer['id']]) : null;
            [$text, $missing] = SmsText::render($in['message'], array_merge([
                'name' => $in['name'] ?: ($customer['name'] ?? null), 'customer_name' => $customer['name'] ?? ($in['name'] ?: null),
                'employee_name' => $customer['employee_name'] ?? null, 'company' => SmsService::company(), 'mobile' => $mobile,
                'date' => date('d-m-Y'),
                'amount' => isset($outstanding['amount']) && $outstanding['amount'] !== null ? SmsService::amount(\App\Core\Money::fromDb($outstanding['amount'])) : null,
                'invoice_no' => $outstanding['first_invoice'] ?? null,
            ], self::contextVars($ctx, $customer)));
            if ($missing) {
                $errors['message'] = 'No value for {' . implode('}, {', $missing) . '} - type it into the message instead.';
            } elseif (SmsText::measure($text)['segments'] > SmsText::MAX_SEGMENTS) {
                $errors['message'] = 'The message is longer than ' . SmsText::MAX_SEGMENTS . ' SMS parts; shorten it.';
            }
        }
        if ($errors || isset($_POST['preview'])) {
            $_SESSION['_old'] = $_POST;
            if ($errors) {
                $_SESSION['_errors'] = $errors;
                Session::flash('error', 'Please correct the highlighted fields.');
            } else {
                $_SESSION['_preview'] = ['text' => $text, 'mobile' => $mobile] + SmsText::measure($text);
            }
            Response::redirect('/sms/send');
            return;
        }

        try {
            $ids = SmsService::queue([['mobile' => $mobile, 'name' => $in['name'] ?: ($customer['name'] ?? null), 'customer_id' => $customer['id'] ?? ($ctx['customer_id'] ?? null),
                'lead_id' => $ctx['lead_id'] ?? null, 'employee_id' => null, 'message' => $text]], $template['id'] ?? null, null, null, (int) $user['id']);
            $r = SmsService::dispatch(1, $ids);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect('/sms/send');
            return;
        }
        Audit::log('sms.sent', 'sms', $ids[0], null, ['to' => SmsService::mask($mobile), 'template_id' => $template['id'] ?? null, 'test' => self::testMode(), 'for' => $ctx['label'] ?? null]);
        Session::flash($r['sent'] ? 'success' : 'error', $r['sent']
            ? (self::testMode() ? 'Test message recorded (test mode - no real SMS was sent).' : 'SMS sent.')
            : 'The SMS could not be sent: ' . (Database::value('SELECT error_message FROM sms_messages WHERE id = ?', [$ids[0]]) ?: 'unknown error'));
        // Users who can send but not open SMS history (e.g. the coordinator) stay on Send SMS
        Response::redirect(Gate::allows('sms.view', $user) ? "/sms/messages/{$ids[0]}" : '/sms/send');
    }

    // ---- campaigns ----------------------------------------------------------------

    public static function campaigns(): void
    {
        Response::view('sms/campaigns', [
            'title'     => 'SMS campaigns',
            'flash'     => Session::takeFlash(),
            'campaigns' => Database::fetchAll(
                'SELECT c.*, t.name AS template_name, u.name AS user_name FROM sms_campaigns c LEFT JOIN sms_templates t ON t.id = c.template_id
                 LEFT JOIN users u ON u.id = c.created_by' . (Gate::isSuper() ? '' : ' WHERE c.created_by = ' . (int) Auth::id()) . ' ORDER BY c.id DESC LIMIT 100'
            ),
            'testMode'  => self::testMode(),
        ]);
    }

    public static function newCampaign(): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        [$bw, $bp] = $scope->branchListWhere('id');
        [$ew, $ep] = $scope->where('branch_id', 'id');
        Response::view('sms/campaign_form', [
            'title'     => 'New SMS campaign',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_errors', []),
            'values'    => Session::pull('_old', []) ?: ['target_type' => 'customers'],
            'templates' => self::templates(),
            'branches'  => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bw} ORDER BY name", $bp),
            'employees' => Database::fetchAll("SELECT id, name, short_name FROM employees WHERE deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1 AND {$ew} ORDER BY name", $ep),
        ]);
    }

    public static function createCampaign(): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        $in = [
            'name'         => Request::input('name', 150),
            'template_id'  => (int) Request::input('template_id', 10) ?: null,
            'message'      => trim((string) ($_POST['message'] ?? '')),
            'target_type'  => Request::input('target_type', 20),
            'branch_id'    => (int) Request::input('branch_id', 10) ?: null,
            'employee_id'  => (int) Request::input('employee_id', 10) ?: null,
            'overdue_only' => Request::input('overdue_only', 1) === '1',
            'scheduled_at' => Request::input('scheduled_at', 16),
        ];
        $errors = [];
        if ($in['name'] === '') {
            $errors['name'] = 'Give the campaign a name.';
        }
        $template = $in['template_id'] ? Database::fetch("SELECT * FROM sms_templates WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$in['template_id']]) : null;
        $body = $in['message'] !== '' ? $in['message'] : ($template['body'] ?? '');
        if ($body === '') {
            $errors['message'] = 'Choose a template or write the message.';
        } elseif ($unknown = SmsText::unknownPlaceholders($body)) {
            $errors['message'] = 'Unknown placeholder(s): {' . implode('}, {', $unknown) . '}.';
        }
        if (!array_key_exists($in['target_type'], SmsService::TARGETS)) {
            $errors['target_type'] = 'Choose who receives it.';
        }
        if ($in['branch_id'] !== null && !$scope->allowsBranch($in['branch_id'])) {
            $errors['branch_id'] = 'You do not have access to this branch.';
        }
        if ($in['employee_id'] !== null && !$scope->allowsEmployee($in['employee_id'])) {
            $errors['employee_id'] = 'You do not have access to this employee.';
        }
        $scheduled = null;
        if ($in['scheduled_at'] !== '') {
            $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $in['scheduled_at']);
            if ($at === false || $at <= new DateTimeImmutable()) {
                $errors['scheduled_at'] = 'Schedule time must be in the future (or leave it empty to send when you start it).';
            } elseif (self::category($template) === 'promotional' && !self::promoWindow($at)) {
                $errors['scheduled_at'] = 'Promotional SMS may only be sent between 09:00 and 21:00.';
            } else {
                $scheduled = $at->format('Y-m-d H:i:00');
            }
        }
        if ($errors) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old'] = $_POST;
            Session::flash('error', 'Please correct the highlighted fields.');
            Response::redirect('/sms/campaigns/new');
            return;
        }
        $filter = array_filter(['branch_id' => $in['branch_id'], 'employee_id' => $in['employee_id'], 'overdue_only' => $in['target_type'] === 'customers' && $in['overdue_only'] ? true : null]);
        Database::query(
            "INSERT INTO sms_campaigns (name, template_id, message_body, target_type, target_filter, scheduled_at, status, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?)",
            [$in['name'], $template['id'] ?? null, $body, $in['target_type'], json_encode((object) $filter), $scheduled, $user['id'], $user['id']]
        );
        $id = (int) Database::connection()->lastInsertId();
        Audit::log('sms_campaign.created', 'sms', $id, null, ['name' => $in['name'], 'target' => $in['target_type'], 'filter' => $filter]);
        Session::flash('success', 'Campaign saved as a draft. Check the recipients and messages below, then start it.');
        Response::redirect("/sms/campaigns/{$id}");
    }

    public static function campaign(array $p): void
    {
        $c = self::findCampaign($p);
        if ($c === null) {
            return;
        }
        $preview = $c['status'] === 'draft' ? self::build($c) : null;
        Response::view('sms/campaign', [
            'title'    => $c['name'] . ' · SMS campaign',
            'flash'    => Session::takeFlash(),
            'c'        => $c,
            'preview'  => $preview,
            'byStatus' => Database::query('SELECT status, COUNT(*) FROM sms_messages WHERE campaign_id = ? GROUP BY status', [$c['id']])->fetchAll(\PDO::FETCH_KEY_PAIR),
            'testMode' => self::testMode(),
            'category' => self::category($c['template_id'] ? Database::fetch('SELECT category FROM sms_templates WHERE id = ?', [$c['template_id']]) : null),
        ]);
    }

    public static function startCampaign(array $p): void
    {
        $c = self::findCampaign($p);
        if ($c === null) {
            return;
        }
        if ($c['status'] !== 'draft') {
            Session::flash('error', 'This campaign has already been started.');
            Response::redirect("/sms/campaigns/{$c['id']}");
            return;
        }
        $template = $c['template_id'] ? Database::fetch('SELECT category FROM sms_templates WHERE id = ?', [$c['template_id']]) : null;
        if ($c['scheduled_at'] === null && self::category($template) === 'promotional' && !self::promoWindow(new DateTimeImmutable())) {
            Session::flash('error', 'Promotional SMS may only be sent between 09:00 and 21:00. Schedule it for a later time instead.');
            Response::redirect("/sms/campaigns/{$c['id']}");
            return;
        }
        $built = self::build($c);
        if ($built['ready'] === []) {
            Session::flash('error', 'Nobody can receive this campaign (see the exclusions).');
            Response::redirect("/sms/campaigns/{$c['id']}");
            return;
        }
        try {
            $ids = Database::transaction(static function () use ($c, $built): array {
                $ids = SmsService::queue($built['ready'], $c['template_id'] !== null ? (int) $c['template_id'] : null, (int) $c['id'], $c['scheduled_at'], (int) Auth::id());
                Database::query("UPDATE sms_campaigns SET status = ?, total_recipients = ?, updated_by = ? WHERE id = ?",
                    [$c['scheduled_at'] !== null ? 'scheduled' : 'processing', count($ids), Auth::id(), $c['id']]);
                return $ids;
            });
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect("/sms/campaigns/{$c['id']}");
            return;
        }
        Audit::log('sms_campaign.started', 'sms', (int) $c['id'], null, ['recipients' => count($ids), 'scheduled_at' => $c['scheduled_at'], 'test' => self::testMode()]);
        if ($c['scheduled_at'] === null) {
            $r = SmsService::dispatch(self::INLINE_LIMIT, $ids);
            $left = count($ids) - $r['sent'] - $r['failed'];
            Session::flash('success', "Sent {$r['sent']}, failed {$r['failed']}" . ($left > 0 ? ", {$left} will be sent by the background job" : '') . (self::testMode() ? ' (test mode - no real SMS).' : '.'));
        } else {
            Session::flash('success', count($ids) . ' message(s) scheduled for ' . date('d-m-Y H:i', strtotime($c['scheduled_at'])) . '.');
        }
        Response::redirect("/sms/campaigns/{$c['id']}");
    }

    public static function cancelCampaign(array $p): void
    {
        $c = self::findCampaign($p);
        if ($c === null) {
            return;
        }
        $n = SmsService::cancelCampaign((int) $c['id'], (int) Auth::id());
        Session::flash('success', "Campaign cancelled; {$n} unsent message(s) will not be sent.");
        Response::redirect("/sms/campaigns/{$c['id']}");
    }

    // ---- templates ----------------------------------------------------------------

    public static function templatesPage(): void
    {
        $edit = isset($_GET['edit']) ? Database::fetch('SELECT * FROM sms_templates WHERE id = ? AND deleted_at IS NULL', [(int) $_GET['edit']]) : null;
        Response::view('sms/templates', [
            'title'     => 'SMS templates',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_errors', []),
            'values'    => Session::pull('_old', []) ?: ($edit ?? ['category' => 'transactional']),
            'edit'      => $edit,
            'templates' => Database::fetchAll('SELECT t.*, (SELECT COUNT(*) FROM sms_messages s WHERE s.template_id = t.id) AS used FROM sms_templates t WHERE t.deleted_at IS NULL ORDER BY t.status, t.name'),
        ]);
    }

    public static function saveTemplate(): void
    {
        $id = (int) Request::input('id', 10) ?: null;
        $in = [
            'name'            => Request::input('name', 100),
            'category'        => Request::input('category', 20),
            'body'            => trim((string) ($_POST['body'] ?? '')),
            'sender_id'       => strtoupper(Request::input('sender_id', 20)) ?: null,
            'dlt_template_id' => Request::input('dlt_template_id', 50) ?: null,
            'status'          => Request::input('status', 10) === 'inactive' ? 'inactive' : 'active',
        ];
        $errors = [];
        if ($in['name'] === '') {
            $errors['name'] = 'Enter a name.';
        } elseif (Database::value('SELECT 1 FROM sms_templates WHERE name = ? AND id <> ?', [$in['name'], $id ?? 0])) {
            $errors['name'] = 'Another template has this name.';
        }
        if (!array_key_exists($in['category'], self::CATEGORIES)) {
            $errors['category'] = 'Choose a category.';
        }
        if ($in['body'] === '' || mb_strlen($in['body']) > 1000) {
            $errors['body'] = 'Enter the message (up to 1,000 characters).';
        } elseif ($unknown = SmsText::unknownPlaceholders($in['body'])) {
            $errors['body'] = 'Unknown placeholder(s): {' . implode('}, {', $unknown) . '}. Allowed: {' . implode('}, {', array_keys(SmsText::PLACEHOLDERS)) . '}.';
        }
        if ($in['sender_id'] !== null && !preg_match('/^[A-Z]{6}$/', $in['sender_id'])) {
            $errors['sender_id'] = 'Sender ID is 6 capital letters (as registered on DLT).';
        }
        if ($id !== null && !Database::value('SELECT 1 FROM sms_templates WHERE id = ? AND deleted_at IS NULL', [$id])) {
            Response::error(404, 'Template not found.');
            return;
        }
        if ($errors) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old'] = $_POST;
            Session::flash('error', 'Please correct the highlighted fields.');
            Response::redirect('/sms/templates' . ($id ? "?edit={$id}" : ''));
            return;
        }
        if ($id === null) {
            Database::query('INSERT INTO sms_templates (name, category, body, sender_id, dlt_template_id, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$in['name'], $in['category'], $in['body'], $in['sender_id'], $in['dlt_template_id'], $in['status'], Auth::id(), Auth::id()]);
            $id = (int) Database::connection()->lastInsertId();
            Audit::log('sms_template.created', 'sms', $id, null, $in);
        } else {
            Database::query('UPDATE sms_templates SET name = ?, category = ?, body = ?, sender_id = ?, dlt_template_id = ?, status = ?, updated_by = ? WHERE id = ?',
                [$in['name'], $in['category'], $in['body'], $in['sender_id'], $in['dlt_template_id'], $in['status'], Auth::id(), $id]);
            Audit::log('sms_template.updated', 'sms', $id, null, $in);
        }
        Session::flash('success', "Template \"{$in['name']}\" saved.");
        Response::redirect('/sms/templates');
    }

    // -------------------------------------------------------------------------

    /** Render every recipient's message; those with an empty placeholder are left out with a reason. */
    private static function build(array $c): array
    {
        $filter = json_decode((string) $c['target_filter'], true) ?: [];
        $r = SmsService::recipients($c['target_type'], $filter, Auth::user(), new DateTimeImmutable('today'));
        $ready = [];
        $missing = 0;
        $segments = 0;
        foreach ($r['recipients'] as $rec) {
            [$text, $miss] = SmsText::render($c['message_body'], $rec['vars']);
            if ($miss) {
                $missing++;
                continue;
            }
            $segments += SmsText::measure($text)['segments'];
            $ready[] = ['mobile' => $rec['mobile'], 'name' => $rec['name'], 'customer_id' => $rec['customer_id'], 'lead_id' => $rec['lead_id'],
                        'employee_id' => $rec['employee_id'], 'message' => $text];
        }
        return ['ready' => $ready, 'excluded' => $r['excluded'] + ['missing_value' => $missing], 'segments' => $segments];
    }

    private static function findCampaign(array $p): ?array
    {
        $c = Database::fetch('SELECT c.*, t.name AS template_name FROM sms_campaigns c LEFT JOIN sms_templates t ON t.id = c.template_id WHERE c.id = ?', [(int) ($p['id'] ?? 0)]);
        if ($c === null || (!Gate::isSuper() && (int) $c['created_by'] !== Auth::id())) {
            Response::error(404, 'Campaign not found.');
            return null;
        }
        return $c;
    }

    /** Free text is treated as promotional (the stricter rule). */
    private static function category(?array $template): string
    {
        return $template['category'] ?? 'promotional';
    }

    private static function promoWindow(DateTimeImmutable $at): bool
    {
        $h = (int) $at->format('G');
        return $h >= 9 && $h < 21;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function visibility(array $user): array
    {
        return Gate::isSuper($user) || DataScope::for($user)->isUnrestricted() ? ['1 = 1', []] : ['s.created_by = ?', [(int) $user['id']]];
    }

    /** @return list<array<string, mixed>> */
    /**
     * Values from the record the SMS was opened from. They apply only when the message goes to
     * that record's own customer (or, for an enquiry without a customer, with no customer code).
     *
     * @return array<string, string>
     */
    private static function contextVars(?array $ctx, ?array $customer): array
    {
        if ($ctx === null || ($customer !== null && (int) $customer['id'] !== $ctx['customer_id'])
            || ($customer === null && $ctx['customer_id'] !== null)) {
            return [];
        }
        return array_filter($ctx['vars'], static fn ($v) => $v !== null);
    }

    private static function templates(): array
    {
        return Database::fetchAll("SELECT id, name, category, body FROM sms_templates WHERE status = 'active' AND deleted_at IS NULL ORDER BY name");
    }

    public static function testMode(): bool
    {
        try {
            return Gateways::current()->isTest();
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return array<string, bool> */
    private static function abilities(): array
    {
        return ['send' => Gate::allows('sms.send'), 'bulk' => Gate::allows('sms.bulk'), 'manage' => Gate::allows('sms.manage')];
    }
}
