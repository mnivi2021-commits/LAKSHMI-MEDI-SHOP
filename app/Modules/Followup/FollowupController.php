<?php

declare(strict_types=1);

namespace App\Modules\Followup;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Dashboard\Kpi\OpenBills;
use DateTimeImmutable;

/**
 * Screen 3 - Follow up. Pick a sales employee, then one list:
 *   Lead gen pending · Sales pending · Sample / O.P pending · Dispatch details · Payment follow up
 * Payment follow up: pick one of that employee's customers -> open bills with a total,
 * then follow up by mail, SMS or directly (call / visit), which is recorded.
 */
final class FollowupController
{
    public const LISTS = [
        'lead'     => 'Lead gen pending',
        'sales'    => 'Sales pending',
        'sample'   => 'Sample / O.P pending',
        'dispatch' => 'Dispatch details',
        'payment'  => 'Payment follow up',
    ];
    public const MODES = ['email' => 'Mail', 'call' => 'Phone call', 'visit' => 'Direct visit', 'sms' => 'SMS'];

    public static function index(): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        $employees = self::employees($scope);
        $emp = (int) ($_GET['employee'] ?? 0) ?: null;
        if ($emp !== null && !isset($employees[$emp])) {
            $emp = null;
        }
        if ($emp === null && count($employees) === 1) {
            $emp = (int) array_key_first($employees);
        }
        $list = array_key_exists($_GET['list'] ?? '', self::LISTS) ? $_GET['list'] : 'lead';
        if ($list === 'payment' && !Gate::allows('outstanding.view', $user)) {
            $list = 'lead';
        }
        $today = date('Y-m-d');
        $from = self::ymd($_GET['from'] ?? '') ?? date('Y-m-01');
        $to = self::ymd($_GET['to'] ?? '') ?? $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $data = ['rows' => [], 'customers' => [], 'customer' => null, 'history' => []];
        if ($emp !== null) {
            $data = match ($list) {
                'lead'     => ['rows' => self::leads($emp)] + $data,
                'sales'    => ['rows' => self::sales($emp)] + $data,
                'sample'   => ['rows' => self::samples($emp)] + $data,
                'dispatch' => ['rows' => self::dispatch($emp, $from, $to)] + $data,
                'payment'  => self::payment($emp, (int) ($_GET['customer'] ?? 0), $scope),
            };
        }
        Response::view('followup/index', [
            'title'     => 'Follow up',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_fu_errors', []),
            'employees' => $employees,
            'employee'  => $emp,
            'list'      => $list,
            'lists'     => Gate::allows('outstanding.view', $user) ? self::LISTS : array_diff_key(self::LISTS, ['payment' => 1]),
            'from'      => $from,
            'to'        => $to,
            'today'     => $today,
            'company'   => \App\Modules\Sms\SmsService::company(),
            'canSms'    => Gate::allows('sms.send', $user),
            'canRecord' => Gate::allows('outstanding.view', $user),
        ] + $data);
    }

    /** Record a payment follow-up done by mail / phone / visit / SMS, with an optional next date. */
    public static function record(array $p): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        $customerId = (int) ($p['id'] ?? 0);
        $c = Database::fetch('SELECT id, name, branch_id, employee_id FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
        if ($c === null || !$scope->allowsBranch((int) $c['branch_id']) || !$scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
            Response::error(404, 'Customer not found.');
            return;
        }
        $back = '/followup?' . http_build_query(['list' => 'payment', 'employee' => $c['employee_id'], 'customer' => $c['id']]);
        $mode = (string) ($_POST['mode'] ?? '');
        $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
        $next = self::ymd((string) ($_POST['next_date'] ?? ''));
        $errors = [];
        if (!isset(self::MODES[$mode])) {
            $errors['mode'] = 'Choose how you followed up.';
        }
        if ($notes === '') {
            $errors['notes'] = 'Write what the customer said.';
        }
        if (trim((string) ($_POST['next_date'] ?? '')) !== '' && ($next === null || $next <= date('Y-m-d'))) {
            $errors['next_date'] = 'The next follow-up date must be after today.';
        }
        if ($errors) {
            $_SESSION['_fu_errors'] = $errors;
            Session::flash('error', 'Please correct the highlighted fields. Nothing was saved.');
            Response::redirect($back);
            return;
        }
        Database::transaction(static function () use ($c, $mode, $notes, $next, $user): void {
            Database::query(
                "INSERT INTO followups (customer_id, employee_id, followup_at, followup_type, purpose, notes, status, outcome, completed_at, created_by, updated_by)
                 VALUES (?, ?, NOW(), ?, 'collection', ?, 'completed', ?, NOW(), ?, ?)",
                [$c['id'], $c['employee_id'], $mode, 'Payment follow up', $notes, $user['id'], $user['id']]
            );
            if ($next !== null) {
                Database::query(
                    "INSERT INTO followups (customer_id, employee_id, followup_at, followup_type, purpose, notes, status, created_by, updated_by)
                     VALUES (?, ?, ?, 'call', 'collection', 'Next payment follow up', 'pending', ?, ?)",
                    [$c['id'], $c['employee_id'], $next . ' 10:00:00', $user['id'], $user['id']]
                );
            }
        });
        Audit::log('payment.followup', 'customers', (int) $c['id'], null, ['mode' => $mode, 'next' => $next]);
        Session::flash('success', 'Follow-up recorded for ' . $c['name'] . ($next ? '; next follow-up on ' . date('d-m-Y', strtotime($next)) : '') . '.');
        Response::redirect($back);
    }

    // =========================================================================
    // Lists (all limited to one employee who is already in the user's scope)
    // =========================================================================

    /** @return list<array<string, mixed>> open leads and enquiries */
    private static function leads(int $emp): array
    {
        return Database::fetchAll(
            "SELECT l.id, l.record_type, l.lead_number AS number, DATE(l.created_at) AS date, l.name AS customer, l.mobile, l.status,
                    COALESCE((SELECT GROUP_CONCAT(COALESCE(p.name, i.description) ORDER BY i.id SEPARATOR ', ')
                              FROM lead_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.lead_id = l.id), lp.name) AS product,
                    l.expected_value AS value, DATE(l.next_followup_at) AS due
             FROM leads l LEFT JOIN products lp ON lp.id = l.product_id
             WHERE l.deleted_at IS NULL AND l.employee_id = ? AND l.status NOT IN ('won', 'lost')
             ORDER BY l.next_followup_at IS NULL, l.next_followup_at, l.created_at DESC
             LIMIT 300",
            [$emp]
        );
    }

    /** @return list<array<string, mixed>> order lines not yet supplied */
    private static function sales(int $emp): array
    {
        return Database::fetchAll(
            "SELECT v.order_id AS id, v.order_no AS number, v.order_date AS date, c.name AS customer, c.mobile, p.name AS product,
                    v.pending_qty AS qty, p.unit, v.pending_value AS value, v.expected_delivery_date AS due, v.customer_po_no AS ref
             FROM v_pending_order_lines v
             JOIN customers c ON c.id = v.customer_id
             JOIN products p ON p.id = v.product_id
             WHERE v.employee_id = ?
             ORDER BY v.expected_delivery_date IS NULL, v.expected_delivery_date, v.order_date
             LIMIT 300",
            [$emp]
        );
    }

    /** @return list<array<string, mixed>> samples and open DCs waiting for an outcome, plus samples waiting for approval */
    private static function samples(int $emp): array
    {
        return Database::fetchAll(
            "SELECT * FROM (
                SELECT 'Sample' AS kind, v.sample_id AS id, v.document_no AS number, v.document_date AS date, c.name AS customer, c.mobile,
                       p.name AS product, v.quantity AS qty, p.unit, v.sample_value AS value, DATEDIFF(CURDATE(), v.document_date) AS days, 'approved' AS approval
                FROM v_pending_sample_lines v JOIN customers c ON c.id = v.customer_id JOIN products p ON p.id = v.product_id
                WHERE v.employee_id = ?
                UNION ALL
                SELECT 'Sample', s.id, s.document_no, s.document_date, c.name, c.mobile, p.name, i.quantity, p.unit, i.sample_value,
                       DATEDIFF(CURDATE(), s.document_date), 'requested'
                FROM samples s JOIN sample_items i ON i.sample_id = s.id JOIN customers c ON c.id = s.customer_id JOIN products p ON p.id = i.product_id
                WHERE s.employee_id = ? AND s.deleted_at IS NULL AND s.approval_status = 'requested'
                UNION ALL
                SELECT 'Open DC', v.dc_id, v.dc_no, v.dc_date, c.name, c.mobile, p.name, v.quantity, p.unit, v.dc_value,
                       DATEDIFF(CURDATE(), v.dc_date), 'approved'
                FROM v_pending_dc_lines v JOIN customers c ON c.id = v.customer_id JOIN products p ON p.id = v.product_id
                WHERE v.employee_id = ?
             ) x ORDER BY x.date, x.number LIMIT 300",
            [$emp, $emp, $emp]
        );
    }

    /** @return list<array<string, mixed>> goods sent out in the period: invoices and DCs */
    private static function dispatch(int $emp, string $from, string $to): array
    {
        return Database::fetchAll(
            "SELECT * FROM (
                SELECT 'Invoice' AS kind, si.id, si.invoice_no AS number, si.invoice_date AS date, c.name AS customer, c.mobile,
                       (SELECT GROUP_CONCAT(p.name ORDER BY it.id SEPARATOR ', ') FROM sales_invoice_items it JOIN products p ON p.id = it.product_id WHERE it.invoice_id = si.id) AS product,
                       si.total_amount AS value, COALESCE(si.due_date, DATE_ADD(si.invoice_date, INTERVAL c.credit_days DAY)) AS due, si.customer_po_no AS ref
                FROM sales_invoices si JOIN customers c ON c.id = si.customer_id
                WHERE si.employee_id = ? AND si.document_type = 'invoice' AND si.status = 'active' AND si.deleted_at IS NULL
                  AND si.invoice_date BETWEEN ? AND ?
                UNION ALL
                SELECT 'DC', d.id, d.dc_no, d.dc_date, c.name, c.mobile,
                       (SELECT GROUP_CONCAT(p.name ORDER BY it.id SEPARATOR ', ') FROM dc_items it JOIN products p ON p.id = it.product_id WHERE it.dc_id = d.id),
                       (SELECT SUM(dc_value) FROM dc_items WHERE dc_id = d.id), NULL, d.approval_reference
                FROM dc_records d JOIN customers c ON c.id = d.customer_id
                WHERE d.employee_id = ? AND d.deleted_at IS NULL AND d.supply_status <> 'not_supplied' AND d.dc_date BETWEEN ? AND ?
             ) x ORDER BY x.date DESC, x.number LIMIT 500",
            [$emp, $from, $to, $emp, $from, $to]
        );
    }

    /** @return array{rows: list<array<string, mixed>>, customers: list<array<string, mixed>>, customer: ?array<string, mixed>, history: list<array<string, mixed>>} */
    private static function payment(int $emp, int $customerId, DataScope $scope): array
    {
        $bills = OpenBills::sql();
        $customers = Database::fetchAll(
            "SELECT c.id, c.customer_code, c.name, COUNT(*) AS bills, SUM(ob.balance) AS balance, MIN(ob.due_date) AS oldest_due
             FROM {$bills} ob JOIN customers c ON c.id = ob.customer_id
             WHERE c.employee_id = ? AND c.deleted_at IS NULL
             GROUP BY c.id, c.customer_code, c.name ORDER BY c.name",
            [$emp]
        );
        $out = ['rows' => [], 'customers' => $customers, 'customer' => null, 'history' => []];
        if ($customerId === 0 || !in_array($customerId, array_map('intval', array_column($customers, 'id')), true)) {
            return $out;
        }
        $out['customer'] = Database::fetch('SELECT id, customer_code, name, company_name, mobile, email, city, credit_days, employee_id FROM customers WHERE id = ?', [$customerId]);
        $out['rows'] = Database::fetchAll(
            "SELECT ob.invoice_no, ob.invoice_date, ob.due_date, ob.bill_amount, ob.balance,
                    COALESCE((SELECT si.customer_po_no FROM sales_invoices si
                              WHERE si.id = ob.invoice_id
                                 OR (ob.invoice_id IS NULL AND si.invoice_no = ob.invoice_no AND si.customer_id = ob.customer_id
                                     AND si.document_type = 'invoice' AND si.deleted_at IS NULL)
                              ORDER BY si.invoice_date DESC LIMIT 1), '') AS po_ref,
                    DATEDIFF(CURDATE(), ob.due_date) AS overdue_days
             FROM {$bills} ob
             WHERE ob.customer_id = ?
             ORDER BY ob.invoice_date, ob.invoice_no",
            [$customerId]
        );
        $out['history'] = Database::fetchAll(
            "SELECT f.followup_at, f.followup_type, f.status, f.outcome, f.notes, u.name AS by_name
             FROM followups f LEFT JOIN users u ON u.id = f.created_by
             WHERE f.customer_id = ? AND f.purpose = 'collection' AND f.deleted_at IS NULL
             ORDER BY f.followup_at DESC LIMIT 20",
            [$customerId]
        );
        return $out;
    }

    // =========================================================================

    /** Sales employees the user may see. @return array<int, string> */
    private static function employees(DataScope $scope): array
    {
        [$w, $p] = $scope->where('e.branch_id', 'e.id');
        $rows = Database::fetchAll(
            "SELECT e.id, CONCAT(COALESCE(e.short_name, e.name), ' - ', e.name, ' (', b.name, ')') AS label
             FROM employees e JOIN branches b ON b.id = e.branch_id
             WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.is_sales_rep = 1 AND {$w}
             ORDER BY COALESCE(e.short_name, e.name)",
            $p
        );
        return array_column($rows, 'label', 'id');
    }

    private static function ymd(string $raw): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));
        return $d !== false && $d->format('Y-m-d') === trim($raw) ? $d->format('Y-m-d') : null;
    }

    /** Plain-text statement used for the mail body. @param list<array<string, mixed>> $rows */
    public static function statementText(array $customer, array $rows, string $company): string
    {
        $lines = ["Dear {$customer['name']},", '', 'Please find below the bills pending for payment as on ' . date('d-m-Y') . ':', ''];
        $total = 0;
        foreach ($rows as $i => $r) {
            $bal = Money::fromDb($r['balance']);
            $total += $bal;
            $lines[] = sprintf('%d. Invoice %s dt %s%s - Value %s, Due %s, Due date %s', $i + 1, $r['invoice_no'], date('d-m-Y', strtotime($r['invoice_date'])),
                $r['po_ref'] !== '' ? " (PO {$r['po_ref']})" : '', rupees(Money::fromDb($r['bill_amount']), 2), rupees($bal, 2),
                $r['due_date'] ? date('d-m-Y', strtotime($r['due_date'])) : '-');
        }
        $lines[] = '';
        $lines[] = 'Total due: ' . rupees($total, 2);
        $lines[] = '';
        $lines[] = 'Kindly arrange the payment at the earliest. Please ignore if already paid.';
        $lines[] = '';
        $lines[] = "Regards,\n{$company}";
        return implode("\n", $lines);
    }
}
