<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Imports\Importer;
use App\Services\Mail\IncomingMail;
use App\Services\Mail\Providers;
use DateTimeImmutable;
use RuntimeException;

/** Mail inbox: counters by category, list, detail with correction / assignment / status / lead / customer. */
final class MailController
{
    private const PER_PAGE = 50;

    public static function index(): void
    {
        $user = Auth::user();
        $f = self::filters($user);
        $counts = MailQuery::countByCategory($user, $f);
        [$w, $p] = MailQuery::where($user, $f);
        $total = (int) Database::value("SELECT COUNT(*) FROM email_messages m WHERE {$w}", $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
        $emails = Database::fetchAll(
            "SELECT m.*, c.code AS category_code, c.name AS category_name, c.color, cu.name AS customer_name, cu.customer_code,
                    l.lead_number, e.short_name AS employee, b.branch_code,
                    (SELECT u.name FROM email_assignments ea JOIN users u ON u.id = ea.assigned_to WHERE ea.email_id = m.id AND ea.is_current = 1 LIMIT 1) AS assignee
             FROM email_messages m
             LEFT JOIN email_categories c ON c.id = m.category_id
             LEFT JOIN customers cu ON cu.id = m.customer_id
             LEFT JOIN leads l ON l.id = m.lead_id
             LEFT JOIN employees e ON e.id = m.employee_id
             LEFT JOIN branches b ON b.id = m.branch_id
             WHERE {$w} ORDER BY m.received_at DESC, m.id DESC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $p
        );
        Response::view('mail/index', [
            'title'      => 'Mail',
            'flash'      => Session::takeFlash(),
            'filters'    => $f,
            'counts'     => $counts,
            'categories' => self::categories(),
            'emails'     => $emails,
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'review'     => MailQuery::count($user, array_merge($f, ['category' => null, 'review' => true])),
            'canEdit'    => Gate::allows('mail.edit'),
            'canManage'  => Gate::allows('mail.manage'),
        ]);
    }

    public static function show(array $p): void
    {
        $email = self::find($p);
        if ($email === null) {
            return;
        }
        $user = Auth::user();
        $scope = DataScope::for($user);
        [$bSql, $bParams] = $scope->where('id', null);
        Response::view('mail/show', [
            'title'      => ($email['subject'] ?: '(no subject)') . ' · Mail',
            'flash'      => Session::takeFlash(),
            'email'      => $email,
            'categories' => self::categories(),
            'customer'   => $email['customer_id'] ? Database::fetch('SELECT id, customer_code, name, mobile FROM customers WHERE id = ?', [$email['customer_id']]) : null,
            'lead'       => $email['lead_id'] ? Database::fetch('SELECT id, lead_number, name, status FROM leads WHERE id = ?', [$email['lead_id']]) : null,
            'assignee'   => Database::fetch('SELECT u.id, u.name, ea.created_at, ea.note FROM email_assignments ea JOIN users u ON u.id = ea.assigned_to WHERE ea.email_id = ? AND ea.is_current = 1', [$email['id']]),
            'activity'   => Database::fetchAll('SELECT a.*, u.name AS user_name FROM email_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.email_id = ? ORDER BY a.created_at DESC, a.id DESC', [$email['id']]),
            'attachments'=> Database::fetchAll('SELECT original_name, mime_type, size_bytes FROM email_attachments WHERE email_id = ?', [$email['id']]),
            'users'      => self::assignableUsers($user),
            'branches'   => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bSql} ORDER BY name", $bParams),
            'account'    => Database::fetch('SELECT email_address, display_name FROM email_accounts WHERE id = ?', [$email['account_id']]),
            'canEdit'    => Gate::allows('mail.edit'),
            'canLead'    => Gate::allows('mail.edit') && Gate::allows('leads.add'),
        ]);
    }

    public static function create(): void
    {
        Response::view('mail/form', [
            'title'    => 'Add email',
            'flash'    => Session::takeFlash(),
            'errors'   => Session::pull('_errors', []),
            'values'   => Session::pull('_old', []) ?: ['received_at' => date('Y-m-d\TH:i')],
            'accounts' => Database::fetchAll('SELECT id, email_address, display_name FROM email_accounts WHERE is_active = 1 ORDER BY id'),
        ]);
    }

    /** Manual entry: staff paste an email they received; it is classified and matched like a synced one. */
    public static function store(): void
    {
        $in = [
            'account_id'  => (int) Request::input('account_id', 10),
            'from_email'  => strtolower(Request::input('from_email', 150)),
            'from_name'   => Request::input('from_name', 150),
            'subject'     => Request::input('subject', 500),
            'body'        => Request::input('body', 5000),
            'received_at' => Request::input('received_at', 16),
        ];
        $errors = [];
        if (!Database::value('SELECT 1 FROM email_accounts WHERE id = ? AND is_active = 1', [$in['account_id']])) {
            $errors['account_id'] = 'Choose the mailbox.';
        }
        if (filter_var($in['from_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_email'] = 'Enter the sender\'s email address.';
        }
        if ($in['subject'] === '' && $in['body'] === '') {
            $errors['subject'] = 'Enter the subject or the message.';
        }
        $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $in['received_at']);
        if ($at === false) {
            $errors['received_at'] = 'Enter when the email was received.';
        } elseif ($at > new DateTimeImmutable('+5 minutes')) {
            $errors['received_at'] = 'Received time cannot be in the future.';
        }
        if ($errors) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old'] = $_POST;
            Session::flash('error', 'Please correct the highlighted fields.');
            Response::redirect('/mail/new');
            return;
        }
        $preview = trim(preg_replace('/\s+/u', ' ', $in['body']));
        $id = MailService::receive($in['account_id'], new IncomingMail(
            providerMessageId: 'manual-' . bin2hex(random_bytes(8)),
            fromEmail: $in['from_email'],
            fromName: $in['from_name'] !== '' ? $in['from_name'] : null,
            subject: $in['subject'] !== '' ? $in['subject'] : null,
            bodyPreview: $preview !== '' ? mb_substr($preview, 0, 1000) : null,
            receivedAt: $at->format('Y-m-d H:i:00'),
        ), (int) Auth::id());
        Audit::log('email.added', 'mail', $id, null, ['from' => $in['from_email'], 'subject' => mb_substr($in['subject'], 0, 100)]);
        $cat = Database::fetch('SELECT c.name, m.classification_confidence FROM email_messages m LEFT JOIN email_categories c ON c.id = m.category_id WHERE m.id = ?', [$id]);
        Session::flash('success', "Email added and classified as {$cat['name']}" . ($cat['classification_confidence'] !== null ? " ({$cat['classification_confidence']}% sure)." : ' (no rule matched).'));
        Response::redirect("/mail/{$id}");
    }

    public static function category(array $p): void
    {
        $email = self::find($p);
        if ($email === null) {
            return;
        }
        $cat = (int) Request::input('category_id', 10);
        if (!Database::value("SELECT 1 FROM email_categories WHERE id = ? AND status = 'active'", [$cat])) {
            Session::flash('error', 'Choose a category.');
        } else {
            MailService::recategorise($email, $cat, (int) Auth::id());
            Session::flash('success', 'Category corrected. The original automatic category is kept in the history.');
        }
        Response::redirect("/mail/{$email['id']}");
    }

    public static function assign(array $p): void
    {
        $email = self::find($p);
        if ($email === null) {
            return;
        }
        $user = Auth::user();
        $targetId = (int) Request::input('user_id', 10);
        $target = array_values(array_filter(self::assignableUsers($user), static fn ($u) => (int) $u['id'] === $targetId))[0] ?? null;
        if ($target === null) {
            Session::flash('error', 'Choose someone you can assign to.');
        } else {
            MailService::assign($email, $target, (int) $user['id'], Request::input('note', 500) ?: null);
            Session::flash('success', "Assigned to {$target['name']}.");
        }
        Response::redirect("/mail/{$email['id']}");
    }

    public static function status(array $p): void
    {
        $email = self::find($p);
        if ($email === null) {
            return;
        }
        $status = Request::input('status', 20);
        $followup = Request::input('followup_status', 10);
        if (!array_key_exists($status, MailService::STATUSES) || !in_array($followup, ['none', 'pending', 'done'], true)) {
            Session::flash('error', 'Choose a valid status.');
        } else {
            MailService::setStatus($email, $status, $followup, (int) Auth::id());
            Session::flash('success', 'Status updated.');
        }
        Response::redirect("/mail/{$email['id']}");
    }

    public static function linkCustomer(array $p): void
    {
        $email = self::find($p);
        if ($email === null) {
            return;
        }
        $code = strtoupper(Request::input('customer_code', 30));
        $c = Database::fetch('SELECT id, customer_code, branch_id, employee_id FROM customers WHERE customer_code = ? AND deleted_at IS NULL', [$code]);
        $scope = DataScope::for(Auth::user());
        if ($c === null || !$scope->allowsBranch((int) $c['branch_id']) || !$scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
            Session::flash('error', "Customer {$code} was not found in your customers.");
        } else {
            MailService::linkCustomer($email, $c, (int) Auth::id());
            Session::flash('success', "Linked to customer {$c['customer_code']}.");
        }
        Response::redirect("/mail/{$email['id']}");
    }

    public static function createLead(array $p): void
    {
        $email = self::find($p);
        if ($email === null) {
            return;
        }
        if (!Gate::authorize('leads.add')) {
            return;
        }
        if ($email['lead_id'] !== null || $email['customer_id'] !== null) {
            Session::flash('error', 'This email is already linked to a ' . ($email['lead_id'] !== null ? 'lead.' : 'customer.'));
            Response::redirect("/mail/{$email['id']}");
            return;
        }
        $user = Auth::user();
        $scope = DataScope::for($user);
        $branchId = (int) Request::input('branch_id', 10);
        if ($branchId === 0 || !$scope->allowsBranch($branchId) || !Database::value('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL', [$branchId])) {
            Session::flash('error', 'Choose a branch for the lead.');
            Response::redirect("/mail/{$email['id']}");
            return;
        }
        $employeeId = $email['employee_id'] !== null ? (int) $email['employee_id'] : (!$scope->isUnrestricted() && $user['employee_id'] ? (int) $user['employee_id'] : null);
        $leadId = MailService::createLead($email, $branchId, $employeeId, (int) $user['id']);
        Session::flash('success', 'Lead created from this email.');
        Response::redirect("/leads/{$leadId}");
    }

    // ---- settings (mail.manage) ------------------------------------------------

    public static function settings(): void
    {
        Response::view('mail/settings', [
            'title'      => 'Mail settings',
            'flash'      => Session::takeFlash(),
            'accounts'   => Database::fetchAll('SELECT a.*, b.branch_code, (SELECT COUNT(*) FROM email_messages m WHERE m.account_id = a.id) AS emails FROM email_accounts a LEFT JOIN branches b ON b.id = a.branch_id ORDER BY a.id'),
            'categories' => Database::fetchAll('SELECT * FROM email_categories ORDER BY sort_order, id'),
            'branches'   => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL ORDER BY name"),
        ]);
    }

    public static function saveKeywords(array $p): void
    {
        $cat = Database::fetch('SELECT * FROM email_categories WHERE id = ?', [(int) ($p['id'] ?? 0)]);
        if ($cat === null) {
            Response::error(404, 'Not found.');
            return;
        }
        $terms = [];
        $bad = [];
        foreach (preg_split('/\R/', (string) ($_POST['keywords'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(.{2,60}?)\s*(?:[:=]\s*(\d{1,2}))?$/u', $line, $m) || (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 10))) {
                $bad[] = $line;
                continue;
            }
            $terms[] = ['term' => mb_strtolower(trim($m[1])), 'weight' => isset($m[2]) ? (int) $m[2] : 1];
        }
        if ($bad || count($terms) > 60) {
            Session::flash('error', $bad ? 'Not understood: ' . implode(', ', array_slice($bad, 0, 5)) . '. Use one "word or phrase : weight (1-10)" per line.' : 'At most 60 keywords per category.');
        } else {
            Database::query('UPDATE email_categories SET keywords = ? WHERE id = ?', [json_encode($terms, JSON_UNESCAPED_UNICODE), $cat['id']]);
            Audit::log('email_category.keywords_changed', 'mail', (int) $cat['id'], ['keywords' => $cat['keywords']], ['keywords' => $terms]);
            Session::flash('success', "Keywords for {$cat['name']} saved. Use \"Re-check emails\" to apply them to emails not corrected by hand.");
        }
        Response::redirect('/mail/settings');
    }

    public static function reclassify(): void
    {
        $n = MailService::reclassify((int) Auth::id());
        Audit::log('email.reclassified', 'mail', null, null, ['changed' => $n]);
        Session::flash('success', "Re-checked all emails not corrected by hand: {$n} changed category.");
        Response::redirect('/mail/settings');
    }

    public static function saveAccount(): void
    {
        $address = strtolower(Request::input('email_address', 150));
        $provider = Request::input('provider', 20);
        $branch = (int) Request::input('branch_id', 10) ?: null;
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || !array_key_exists($provider, Providers::LABELS)) {
            Session::flash('error', 'Enter the mailbox address and choose how it is read.');
        } elseif (Database::value('SELECT 1 FROM email_accounts WHERE email_address = ?', [$address])) {
            Session::flash('error', "{$address} is already set up.");
        } else {
            Database::query('INSERT INTO email_accounts (email_address, display_name, provider, branch_id, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$address, Request::input('display_name', 100) ?: null, $provider, $branch, Auth::id(), Auth::id()]);
            Audit::log('email_account.created', 'mail', (int) Database::connection()->lastInsertId(), null, ['address' => $address, 'provider' => $provider]);
            Session::flash('success', "Mailbox {$address} added. Passwords and keys are read from .env only, never stored here.");
        }
        Response::redirect('/mail/settings');
    }

    public static function sync(array $p): void
    {
        $account = Database::fetch('SELECT * FROM email_accounts WHERE id = ? AND is_active = 1', [(int) ($p['id'] ?? 0)]);
        if ($account === null) {
            Response::error(404, 'Not found.');
            return;
        }
        $why = Providers::for($account['provider'])->unavailableReason();
        if ($why !== null) {
            Session::flash('error', $why);
        } else {
            try {
                $r = MailService::sync($account);
                Session::flash('success', "Fetched {$r['received']} new email(s).");
            } catch (RuntimeException $e) {
                Session::flash('error', 'Sync failed: ' . $e->getMessage());
            }
        }
        Response::redirect('/mail/settings');
    }

    /** Counts for the mobile app (same numbers as the dashboard cards). */
    public static function api(): void
    {
        $user = Auth::user();
        $f = self::filters($user);
        Response::json(['success' => true, 'data' => ['filters' => $f, 'by_category' => MailQuery::countByCategory($user, $f),
            'open' => MailQuery::count($user, array_merge($f, ['open' => true]))]]);
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> validated filters */
    private static function filters(array $user): array
    {
        $codes = array_column(self::categories(), 'code');
        $date = static fn (string $k): ?string => ($d = Importer::parseDate((string) ($_GET[$k] ?? ''))) !== null ? $d : null;
        $scope = DataScope::for($user);
        $branch = (int) ($_GET['branch'] ?? 0) ?: null;
        $employee = (int) ($_GET['employee'] ?? 0) ?: null;
        $customer = (int) ($_GET['customer'] ?? 0) ?: null;
        return [
            'category' => in_array($_GET['category'] ?? '', $codes, true) ? $_GET['category'] : null,
            'status'   => array_key_exists($_GET['status'] ?? '', MailService::STATUSES) ? $_GET['status'] : null,
            'open'     => ($_GET['open'] ?? '') === '1',
            'from'     => $date('from'),
            'to'       => $date('to'),
            'branch'   => $branch !== null && $scope->allowsBranch($branch) ? $branch : null,
            'employee' => $employee !== null && $scope->allowsEmployee($employee) ? $employee : null,
            'customer' => $customer,
            'q'        => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100) ?: null,
            'review'   => ($_GET['review'] ?? '') === '1',
            'mine'     => ($_GET['mine'] ?? '') === '1',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function categories(): array
    {
        static $cache = null;
        return $cache ??= Database::fetchAll("SELECT id, code, name, color FROM email_categories WHERE status = 'active' ORDER BY sort_order, id");
    }

    private static function find(array $p): ?array
    {
        $email = ctype_digit((string) ($p['id'] ?? '')) ? MailQuery::visible(Auth::user(), (int) $p['id']) : null;
        if ($email === null) {
            Response::error(404, 'Email not found.');
        }
        return $email;
    }

    /** Active users the current user may assign to (people within their data scope, and themselves). @return list<array<string, mixed>> */
    private static function assignableUsers(array $user): array
    {
        $scope = DataScope::for($user);
        if ($scope->isUnrestricted()) {
            return Database::fetchAll("SELECT id, name, employee_id FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY name");
        }
        [$w, $p] = $scope->where('e.branch_id', 'e.id');
        return Database::fetchAll(
            "SELECT u.id, u.name, u.employee_id FROM users u LEFT JOIN employees e ON e.id = u.employee_id
             WHERE u.status = 'active' AND u.deleted_at IS NULL AND (u.id = ? OR (e.id IS NOT NULL AND {$w})) ORDER BY u.name",
            array_merge([(int) $user['id']], $p)
        );
    }
}
