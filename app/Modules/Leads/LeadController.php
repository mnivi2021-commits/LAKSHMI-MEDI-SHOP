<?php

declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\DataScope;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NumberSequence;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use DateTimeImmutable;

/**
 * Leads: pipeline New -> Contacted -> Interested -> Follow-up -> Quotation ->
 * Negotiation -> Won / Lost, follow-up log, and conversion into a customer.
 * Lead numbers (LD-00001...) are allocated automatically. Data scope applies:
 * a Sales Executive sees and creates only their own leads.
 */
final class LeadController
{
    public const STATUSES = [
        'new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'follow_up' => 'Follow-up',
        'quotation' => 'Quotation', 'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost',
    ];
    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
    public const FOLLOWUP_TYPES = ['call' => 'Call', 'visit' => 'Visit', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'meeting' => 'Meeting', 'other' => 'Other'];
    private const PER_PAGE = 25;

    public static function index(): void
    {
        $user = Auth::user();
        $f = [
            'q'        => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'status'   => array_key_exists($_GET['status'] ?? '', self::STATUSES) ? $_GET['status'] : '',
            'priority' => array_key_exists($_GET['priority'] ?? '', self::PRIORITIES) ? $_GET['priority'] : '',
            'source'   => (int) ($_GET['source'] ?? 0) ?: null,
            'due'      => ($_GET['due'] ?? '') === '1' ? '1' : '',
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        [$scopeSql, $scopeParams] = DataScope::for($user)->where('l.branch_id', 'l.employee_id');
        $where = ['l.deleted_at IS NULL', $scopeSql];
        $params = $scopeParams;
        if ($f['q'] !== '') {
            $where[] = '(l.lead_number LIKE ? OR l.name LIKE ? OR l.company_name LIKE ? OR l.mobile LIKE ? OR l.email LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        foreach (['status' => 'l.status', 'priority' => 'l.priority', 'source' => 'l.source_id'] as $k => $col) {
            if ($f[$k] !== '' && $f[$k] !== null) {
                $where[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        if ($f['due'] === '1') {
            $where[] = "l.status NOT IN ('won','lost') AND l.next_followup_at <= ?";
            $params[] = (new DateTimeImmutable('today'))->format('Y-m-d 23:59:59');
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) FROM leads l WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $leads = Database::fetchAll(
            "SELECT l.*, s.name AS source_name, p.name AS product_name, b.branch_code, e.short_name AS employee
             FROM leads l
             LEFT JOIN lead_sources s ON s.id = l.source_id
             LEFT JOIN products p ON p.id = l.product_id
             JOIN branches b ON b.id = l.branch_id
             LEFT JOIN employees e ON e.id = l.employee_id
             WHERE {$whereSql}
             ORDER BY FIELD(l.status, 'won', 'lost') ASC, l.next_followup_at IS NULL, l.next_followup_at, l.id DESC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        // Pipeline counts within scope (ignores the status filter so the strip always shows the whole funnel).
        $pipeline = Database::query(
            "SELECT l.status, COUNT(*) AS n, COALESCE(SUM(l.expected_value), 0) AS value FROM leads l
             WHERE l.deleted_at IS NULL AND {$scopeSql} GROUP BY l.status",
            $scopeParams
        )->fetchAll(\PDO::FETCH_UNIQUE);

        Response::view('leads/index', [
            'title'    => 'Leads',
            'flash'    => Session::takeFlash(),
            'leads'    => $leads,
            'filters'  => $f,
            'pipeline' => $pipeline,
            'sources'  => Database::fetchAll("SELECT id, name FROM lead_sources WHERE status = 'active' ORDER BY sort_order, name"),
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'canAdd'   => Gate::allows('leads.add'),
            'canExport'=> Gate::allows('leads.export'),
        ]);
    }

    public static function show(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        $followups = Database::fetchAll(
            "SELECT f.*, e.short_name AS employee, u.name AS created_by_name FROM followups f
             LEFT JOIN employees e ON e.id = f.employee_id LEFT JOIN users u ON u.id = f.created_by
             WHERE f.lead_id = ? AND f.deleted_at IS NULL ORDER BY f.followup_at DESC, f.id DESC",
            [$lead['id']]
        );
        $history = Database::fetchAll(
            "SELECT action, user_name, old_data, new_data, created_at FROM audit_logs
             WHERE module = 'leads' AND record_id = ? ORDER BY id DESC LIMIT 30",
            [$lead['id']]
        );
        Response::view('leads/show', [
            'title'      => $lead['lead_number'] . ' · ' . $lead['name'],
            'flash'      => Session::takeFlash(),
            'errors'     => Session::pull('_errors', []),
            'lead'       => $lead,
            'followups'  => $followups,
            'history'    => $history,
            'canEdit'    => Gate::allows('leads.edit'),
            'canDelete'  => Gate::allows('leads.delete'),
            'canConvert' => Gate::allows('leads.edit') && Gate::allows('customers.add'),
        ]);
    }

    public static function create(): void
    {
        self::form(null);
    }

    public static function edit(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead !== null) {
            self::form($lead);
        }
    }

    public static function store(): void
    {
        [$data, $errors] = self::validated(null);
        if ($errors) {
            self::back('/leads/new', $errors);
            return;
        }
        $id = Database::transaction(static function () use ($data): int {
            $number = NumberSequence::next('lead');
            Database::query(
                'INSERT INTO leads (lead_number, name, company_name, mobile, email, source_id, product_id, branch_id, employee_id,
                                    status, priority, expected_value, next_followup_at, remarks, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$number, $data['name'], $data['company_name'], $data['mobile'], $data['email'], $data['source_id'], $data['product_id'],
                 $data['branch_id'], $data['employee_id'], $data['status'], $data['priority'], $data['expected_value'],
                 $data['next_followup_at'], $data['remarks'], Auth::id(), Auth::id()]
            );
            return (int) Database::connection()->lastInsertId();
        });
        Audit::log('lead.created', 'leads', $id, null, $data);
        Session::flash('success', 'Lead created.');
        Response::redirect("/leads/{$id}");
    }

    public static function update(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        [$data, $errors] = self::validated($lead);
        if ($errors) {
            self::back("/leads/{$lead['id']}/edit", $errors);
            return;
        }
        Database::query(
            'UPDATE leads SET name = ?, company_name = ?, mobile = ?, email = ?, source_id = ?, product_id = ?, branch_id = ?,
                              employee_id = ?, priority = ?, expected_value = ?, next_followup_at = ?, remarks = ?, updated_by = ?
             WHERE id = ?',
            [$data['name'], $data['company_name'], $data['mobile'], $data['email'], $data['source_id'], $data['product_id'],
             $data['branch_id'], $data['employee_id'], $data['priority'], $data['expected_value'], $data['next_followup_at'],
             $data['remarks'], Auth::id(), $lead['id']]
        );
        unset($data['status']);   // status changes go through changeStatus() so the reason / history is captured
        $old = array_intersect_key($lead, $data);
        $changes = array_diff_assoc(array_map('strval', array_map(static fn ($v) => $v ?? '', $data)), array_map('strval', array_map(static fn ($v) => $v ?? '', $old)));
        if ($changes) {
            Audit::log('lead.updated', 'leads', (int) $lead['id'], array_intersect_key($old, $changes), $changes);
        }
        Session::flash('success', 'Lead updated.');
        Response::redirect("/leads/{$lead['id']}");
    }

    public static function changeStatus(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        $status = Request::input('status', 20);
        $reason = Request::input('lost_reason', 255);
        if (!array_key_exists($status, self::STATUSES)) {
            self::backErrors($lead, ['status' => 'Choose a valid status.']);
            return;
        }
        if ($status === 'lost' && $reason === '') {
            self::backErrors($lead, ['lost_reason' => 'Give a reason when marking a lead as lost.']);
            return;
        }
        if ($lead['customer_id'] !== null && $status !== 'won') {
            self::backErrors($lead, ['status' => 'This lead has been converted to a customer; its status stays Won.']);
            return;
        }
        Database::query(
            'UPDATE leads SET status = ?, lost_reason = ?, next_followup_at = IF(? IN (\'won\',\'lost\'), NULL, next_followup_at), updated_by = ? WHERE id = ?',
            [$status, $status === 'lost' ? $reason : null, $status, Auth::id(), $lead['id']]
        );
        Audit::log('lead.status_changed', 'leads', (int) $lead['id'], ['status' => $lead['status']], array_filter(['status' => $status, 'lost_reason' => $status === 'lost' ? $reason : null]));
        Session::flash('success', 'Status changed to ' . self::STATUSES[$status] . '.');
        Response::redirect("/leads/{$lead['id']}");
    }

    public static function addFollowup(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        $at = Request::input('followup_at', 20);
        $type = Request::input('followup_type', 20);
        $notes = Request::input('notes', 1000);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $at) ?: DateTimeImmutable::createFromFormat('!Y-m-d', $at);
        $errors = [];
        if ($d === false) {
            $errors['followup_at'] = 'Choose the follow-up date and time.';
        }
        if (!array_key_exists($type, self::FOLLOWUP_TYPES)) {
            $errors['followup_type'] = 'Choose a follow-up type.';
        }
        if (in_array($lead['status'], ['won', 'lost'], true)) {
            $errors['followup_at'] = 'This lead is closed (' . self::STATUSES[$lead['status']] . '); reopen it before scheduling a follow-up.';
        }
        if ($errors) {
            self::backErrors($lead, $errors);
            return;
        }

        Database::transaction(static function () use ($lead, $d, $type, $notes): void {
            Database::query(
                "INSERT INTO followups (lead_id, employee_id, followup_at, followup_type, purpose, notes, status, created_by, updated_by)
                 VALUES (?, ?, ?, ?, 'sales', ?, 'pending', ?, ?)",
                [$lead['id'], $lead['employee_id'], $d->format('Y-m-d H:i:s'), $type, $notes !== '' ? $notes : null, Auth::id(), Auth::id()]
            );
            self::syncNextFollowup((int) $lead['id']);
            // Scheduling the first follow-up moves a brand-new lead along the pipeline.
            if ($lead['status'] === 'new') {
                Database::query("UPDATE leads SET status = 'follow_up' WHERE id = ?", [$lead['id']]);
            }
        });
        Audit::log('lead.followup_scheduled', 'leads', (int) $lead['id'], null, ['at' => $d->format('Y-m-d H:i'), 'type' => $type]);
        Session::flash('success', 'Follow-up scheduled for ' . $d->format('d-m-Y H:i') . '.');
        Response::redirect("/leads/{$lead['id']}");
    }

    public static function completeFollowup(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        $fu = Database::fetch("SELECT * FROM followups WHERE id = ? AND lead_id = ? AND deleted_at IS NULL", [(int) $p['fid'], $lead['id']]);
        if ($fu === null) {
            Response::error(404, 'Follow-up not found.');
            return;
        }
        $result = Request::input('result', 20) === 'missed' ? 'missed' : 'completed';
        $outcome = Request::input('outcome', 1000);
        Database::transaction(static function () use ($fu, $result, $outcome, $lead): void {
            Database::query('UPDATE followups SET status = ?, outcome = ?, completed_at = NOW(), updated_by = ? WHERE id = ?',
                [$result, $outcome !== '' ? $outcome : null, Auth::id(), $fu['id']]);
            self::syncNextFollowup((int) $lead['id']);
        });
        Audit::log('lead.followup_' . $result, 'leads', (int) $lead['id'], null, ['followup_id' => (int) $fu['id'], 'outcome' => $outcome]);
        Session::flash('success', 'Follow-up marked ' . $result . '.');
        Response::redirect("/leads/{$lead['id']}");
    }

    /** Create a customer from a lead (needs leads.edit + customers.add). */
    public static function convert(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        if (!Gate::allows('customers.add')) {
            Response::error(403, 'You need permission to add customers to convert a lead.');
            return;
        }
        if ($lead['customer_id'] !== null) {
            Session::flash('info', 'This lead is already linked to a customer.');
            Response::redirect("/leads/{$lead['id']}");
            return;
        }
        if (!$lead['mobile'] && !$lead['email']) {
            self::backErrors($lead, ['status' => 'Add a mobile number or email to the lead before converting it.']);
            return;
        }
        if ($lead['mobile'] && Database::value('SELECT 1 FROM customers WHERE mobile = ? AND deleted_at IS NULL', [$lead['mobile']])) {
            self::backErrors($lead, ['status' => 'A customer with this mobile number already exists. Link the lead to that customer instead of creating a duplicate.']);
            return;
        }

        $customerId = Database::transaction(static function () use ($lead): int {
            $code = NumberSequence::next('customer');
            Database::query(
                "INSERT INTO customers (customer_code, name, company_name, mobile, email, branch_id, employee_id, status, lead_id, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)",
                [$code, $lead['company_name'] ?: $lead['name'], $lead['company_name'], $lead['mobile'], $lead['email'],
                 $lead['branch_id'], $lead['employee_id'], $lead['id'], Auth::id(), Auth::id()]
            );
            $cid = (int) Database::connection()->lastInsertId();
            Database::query("UPDATE leads SET customer_id = ?, status = 'won', next_followup_at = NULL, updated_by = ? WHERE id = ?", [$cid, Auth::id(), $lead['id']]);
            Database::query("UPDATE followups SET status = 'cancelled', updated_by = ? WHERE lead_id = ? AND status = 'pending'", [Auth::id(), $lead['id']]);
            return $cid;
        });
        Audit::log('lead.converted', 'leads', (int) $lead['id'], ['status' => $lead['status']], ['status' => 'won', 'customer_id' => $customerId]);
        Audit::log('customer.created', 'customers', $customerId, null, ['from_lead' => $lead['lead_number']]);
        Session::flash('success', 'Lead converted. Customer created.');
        Response::redirect("/customers/{$customerId}");
    }

    public static function destroy(array $p): void
    {
        $lead = self::findInScope((int) $p['id']);
        if ($lead === null) {
            return;
        }
        if ($lead['customer_id'] !== null) {
            Session::flash('error', 'A converted lead is part of the customer history and cannot be deleted.');
            Response::redirect("/leads/{$lead['id']}");
            return;
        }
        Database::query('UPDATE leads SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [Auth::id(), $lead['id']]);
        Database::query("UPDATE followups SET deleted_at = NOW() WHERE lead_id = ?", [$lead['id']]);
        Audit::log('lead.deleted', 'leads', (int) $lead['id'], ['lead_number' => $lead['lead_number'], 'name' => $lead['name']]);
        Session::flash('success', "Lead {$lead['lead_number']} deleted.");
        Response::redirect('/leads');
    }

    public static function export(): void
    {
        [$scopeSql, $params] = DataScope::for(Auth::user())->where('l.branch_id', 'l.employee_id');
        $rows = Database::fetchAll(
            "SELECT l.lead_number, l.name, l.company_name, l.mobile, l.email, s.name AS source, p.name AS product, b.branch_code,
                    e.short_name AS employee, l.status, l.priority, l.expected_value, l.next_followup_at, l.remarks, l.created_at
             FROM leads l LEFT JOIN lead_sources s ON s.id = l.source_id LEFT JOIN products p ON p.id = l.product_id
             JOIN branches b ON b.id = l.branch_id LEFT JOIN employees e ON e.id = l.employee_id
             WHERE l.deleted_at IS NULL AND {$scopeSql} ORDER BY l.id DESC",
            $params
        );
        Audit::log('report.exported', 'leads', null, null, ['report' => 'leads', 'rows' => count($rows)]);
        Csv::download('leads_' . date('Y-m-d') . '.csv',
            ['Lead No', 'Name', 'Company', 'Mobile', 'Email', 'Source', 'Product', 'Branch', 'Employee', 'Status', 'Priority', 'Expected Value', 'Next Follow-up', 'Remarks', 'Created'],
            array_map(static fn (array $r): array => array_values($r), $rows));
    }

    // -------------------------------------------------------------------------

    /** next_followup_at = earliest still-pending follow-up (or null). */
    private static function syncNextFollowup(int $leadId): void
    {
        Database::query(
            "UPDATE leads SET next_followup_at = (SELECT MIN(followup_at) FROM followups WHERE lead_id = ? AND status = 'pending' AND deleted_at IS NULL)
             WHERE id = ?",
            [$leadId, $leadId]
        );
    }

    private static function findInScope(int $id): ?array
    {
        [$scopeSql, $params] = DataScope::for(Auth::user())->where('l.branch_id', 'l.employee_id');
        $lead = Database::fetch(
            "SELECT l.*, s.name AS source_name, p.name AS product_name, b.name AS branch_name, b.branch_code,
                    e.name AS employee_name, e.short_name AS employee_short, c.customer_code, c.name AS customer_name
             FROM leads l
             LEFT JOIN lead_sources s ON s.id = l.source_id
             LEFT JOIN products p ON p.id = l.product_id
             JOIN branches b ON b.id = l.branch_id
             LEFT JOIN employees e ON e.id = l.employee_id
             LEFT JOIN customers c ON c.id = l.customer_id
             WHERE l.id = ? AND l.deleted_at IS NULL AND {$scopeSql}",
            array_merge([$id], $params)
        );
        if ($lead === null) {
            Response::error(404, 'Lead not found.');
        }
        return $lead;
    }

    private static function form(?array $lead): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        $old = Session::pull('_old', []);
        $defaults = ['priority' => 'medium', 'status' => 'new', 'employee_id' => $user['employee_id'] ?? null];
        if (!empty($user['employee_id'])) {
            $defaults['branch_id'] = Database::value('SELECT branch_id FROM employees WHERE id = ?', [$user['employee_id']]);
        }

        [$bSql, $bParams] = $scope->where('id', null);
        [$eSql, $eParams] = $scope->where('branch_id', 'id');
        Response::view('leads/form', [
            'title'     => $lead ? 'Edit lead' : 'Add lead',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_errors', []),
            'lead'      => $lead,
            'values'    => $old ?: ($lead ?? $defaults),
            'sources'   => Database::fetchAll("SELECT id, name FROM lead_sources WHERE status = 'active' ORDER BY sort_order, name"),
            'products'  => Database::fetchAll("SELECT id, name, product_code FROM products WHERE deleted_at IS NULL AND status = 'active' ORDER BY name"),
            'branches'  => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bSql} ORDER BY name", $bParams),
            'employees' => Database::fetchAll("SELECT id, name, short_name FROM employees WHERE deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1 AND {$eSql} ORDER BY name", $eParams),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function validated(?array $existing): array
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        $data = [
            'name'             => Request::input('name', 150),
            'company_name'     => Request::input('company_name', 150) ?: null,
            'mobile'           => Request::input('mobile', 20) ?: null,
            'email'            => mb_strtolower(Request::input('email', 150)) ?: null,
            'source_id'        => (int) Request::input('source_id', 10) ?: null,
            'product_id'       => (int) Request::input('product_id', 10) ?: null,
            'branch_id'        => (int) Request::input('branch_id', 10) ?: null,
            'employee_id'      => (int) Request::input('employee_id', 10) ?: null,
            'status'           => $existing['status'] ?? 'new',
            'priority'         => Request::input('priority', 10) ?: 'medium',
            'expected_value'   => Request::input('expected_value', 20),
            'next_followup_at' => Request::input('next_followup_at', 20),
            'remarks'          => Request::input('remarks', 2000) ?: null,
        ];
        // Own-scope users (e.g. Sales Executive) always own the leads they create.
        if ($scope->scope === 'own' && !empty($user['employee_id'])) {
            $data['employee_id'] = (int) $user['employee_id'];
        }

        $v = (new Validator())->required('name', $data['name'], 'Lead name')->maxLength('name', $data['name'], 150, 'Lead name');
        if ($data['mobile']) {
            $v->mobile('mobile', $data['mobile']);
        }
        if ($data['email']) {
            $v->email('email', $data['email']);
        }
        if (!$data['mobile'] && !$data['email']) {
            $v->add('mobile', 'Provide a mobile number or an email address.');
        }
        if (!array_key_exists($data['priority'], self::PRIORITIES)) {
            $v->add('priority', 'Choose a priority.');
        }

        if ($data['expected_value'] === '') {
            $data['expected_value'] = null;
        } elseif (($paise = Money::parse($data['expected_value'])) === null) {
            $v->add('expected_value', 'Expected value must be an amount like 150000.');
        } else {
            $data['expected_value'] = Money::toDecimal($paise);
        }

        if ($data['next_followup_at'] === '') {
            $data['next_followup_at'] = null;
        } else {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['next_followup_at']) ?: DateTimeImmutable::createFromFormat('!Y-m-d', $data['next_followup_at']);
            if ($d === false) {
                $v->add('next_followup_at', 'Choose a valid date and time.');
            } else {
                $data['next_followup_at'] = $d->format('Y-m-d H:i:s');
            }
        }

        if ($data['source_id'] !== null && !Database::value("SELECT 1 FROM lead_sources WHERE id = ?", [$data['source_id']])) {
            $v->add('source_id', 'Choose a valid source.');
        }
        if ($data['product_id'] !== null && !Database::value("SELECT 1 FROM products WHERE id = ? AND deleted_at IS NULL", [$data['product_id']])) {
            $v->add('product_id', 'Choose a valid product.');
        }
        if ($data['branch_id'] === null || !Database::value("SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL", [$data['branch_id']])) {
            $v->add('branch_id', 'Choose a branch.');
        } elseif (!$scope->allowsBranch($data['branch_id'])) {
            $v->add('branch_id', 'You do not have access to this branch.');
        }
        if ($data['employee_id'] !== null) {
            if (!Database::value("SELECT 1 FROM employees WHERE id = ? AND deleted_at IS NULL AND is_sales_rep = 1", [$data['employee_id']])) {
                $v->add('employee_id', 'Choose a valid sales employee.');
            } elseif (!$scope->allowsEmployee($data['employee_id'])) {
                $v->add('employee_id', 'You can only assign leads to employees in your view.');
            }
        } elseif ($scope->employeeIds !== null) {
            $v->add('employee_id', 'Choose the sales employee who owns this lead.');
        }

        return [$data, $v->errors()];
    }

    /** @param array<string, string> $errors */
    private static function back(string $path, array $errors): void
    {
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = $_POST;
        Session::flash('error', 'Please correct the highlighted fields.');
        Response::redirect($path);
    }

    /** @param array<string, string> $errors */
    private static function backErrors(array $lead, array $errors): void
    {
        foreach ($errors as $msg) {
            Session::flash('error', $msg);
        }
        Response::redirect("/leads/{$lead['id']}");
    }
}
