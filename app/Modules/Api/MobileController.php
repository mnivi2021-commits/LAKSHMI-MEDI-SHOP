<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Dashboard\Kpi\CollectionKpi;
use App\Modules\Dashboard\Kpi\OpenBills;
use App\Modules\Dashboard\Kpi\OutstandingKpi;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;
use App\Modules\Dashboard\Kpi\SalesKpi;
use App\Modules\Dashboard\Kpi\SampleDcKpi;

/**
 * Endpoints for the mobile app (Bearer token). Every figure comes from the same
 * KPI classes as the web dashboard; amounts are decimal rupee strings ("12345.50").
 * Each endpoint re-checks permissions and data scope - the app only hides screens.
 */
final class MobileController
{
    /** GET /api/dashboard/summary - everything the app's home screen shows, in one call. */
    public static function summary(): void
    {
        $user = Auth::user();
        $ctx = DashboardContext::fromRequest($user, $_GET);
        $out = [
            'as_on'    => $ctx->asOn->format('Y-m-d'),
            'fy'       => $ctx->fyRow['label'],
            'is_live'  => $ctx->isLive,
            'notices'  => $ctx->notices,
            'filters'  => $ctx->filters,
            'branches' => self::options($ctx->branchOptions()),
            'employees'=> self::options($ctx->employeeOptions($ctx->filters['branch_id'])),
        ];
        if (Gate::allows('sales.view', $user)) {
            $s = (new SalesKpi($ctx))->summary();
            $out['sales'] = ['total' => self::r($s['sales_total']), 'today' => self::r($s['sales_today']), 'target' => self::r($s['annual_target']),
                'achieved_pct' => $s['achieved_pct'], 'target_pending' => self::r($s['target_pending']), 'month_to_date' => self::r($s['sales_month_to_previous_day'] + $s['sales_today'])];
        }
        if (Gate::allows('collections.view', $user)) {
            $c = (new CollectionKpi($ctx))->summary();
            $out['collection'] = ['month' => self::r($c['total']), 'today' => self::r($c['today']), 'target' => self::r($c['collection_target']),
                'pct' => $c['collection_pct'], 'fy_to_date' => self::r($c['fy_to_date']), 'overdue' => self::r($c['overdue'])];
        }
        if (Gate::allows('pending_orders.view', $user)) {
            $p = (new PendingOrderKpi($ctx))->summary();
            $out['pending'] = ['value' => self::r($p['value']), 'orders' => $p['orders'], 'customers' => $p['customers'], 'oldest_days' => $p['oldest']['age'] ?? null];
        }
        if (Gate::allows('outstanding.view', $user)) {
            $o = (new OutstandingKpi($ctx))->categories();
            $out['outstanding'] = ['upto90' => self::r($o['upto90']['value']), 'd90' => self::r($o['d90']['value']), 'd150' => self::r($o['d150']['value']),
                'd90_customers' => $o['d90']['customers'], 'd150_customers' => $o['d150']['customers'], 'total' => self::r(array_sum(array_column($o, 'value')))];
        }
        if (Gate::allows('samples.view', $user)) {
            $sd = (new SampleDcKpi($ctx))->summary();
            $out['samples_dc'] = ['samples' => self::r($sd['samples']['value']), 'sample_docs' => $sd['samples']['documents'],
                'dc' => self::r($sd['dc']['value']), 'dc_docs' => $sd['dc']['documents']];
        }
        if (Gate::allows('mail.view', $user)) {
            $m = DashboardController::mailPanel($ctx, $user);
            $out['email'] = array_map(static fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'month' => $c['month'], 'day' => $c['day'], 'open' => $c['open']], $m['cards']);
        }
        Response::json(['success' => true, 'data' => $out]);
    }

    /** GET /api/customers?q=&page= */
    public static function customers(): void
    {
        $scope = DataScope::for(Auth::user());
        [$w, $p] = $scope->where('c.branch_id', 'c.employee_id');
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        if ($q !== '') {
            $w .= ' AND (c.name LIKE ? OR c.customer_code LIKE ? OR c.mobile LIKE ? OR c.city LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like, $like, $like);
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $rows = Database::fetchAll(
            "SELECT c.id, c.customer_code, c.name, c.mobile, c.city, c.status, b.branch_code, e.short_name AS employee,
                    (SELECT COALESCE(SUM(ob.balance), 0) FROM " . OpenBills::sql() . " ob WHERE ob.customer_id = c.id) AS outstanding
             FROM customers c JOIN branches b ON b.id = c.branch_id LEFT JOIN employees e ON e.id = c.employee_id
             WHERE c.deleted_at IS NULL AND {$w} ORDER BY c.name LIMIT 30 OFFSET " . (($page - 1) * 30),
            $p
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['outstanding'] = self::r(Money::fromDb($r['outstanding']));
        }
        Response::json(['success' => true, 'data' => ['customers' => $rows, 'page' => $page]]);
    }

    /** GET /api/customers/{id} - profile, open bills (oldest first), recent invoices and receipts. */
    public static function customer(array $params): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        $c = Database::fetch(
            "SELECT c.id, c.customer_code, c.name, c.company_name, c.mobile, c.email, c.city, c.state, c.gstin, c.credit_days, c.status,
                    b.branch_code, b.name AS branch, e.name AS employee, c.branch_id, c.employee_id
             FROM customers c JOIN branches b ON b.id = c.branch_id LEFT JOIN employees e ON e.id = c.employee_id
             WHERE c.id = ? AND c.deleted_at IS NULL", [(int) ($params['id'] ?? 0)]);
        if ($c === null || !$scope->allowsBranch((int) $c['branch_id']) || !$scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
            Response::json(['success' => false, 'error' => ['code' => 404, 'type' => 'not_found', 'message' => 'Customer not found.']], 404);
            return;
        }
        unset($c['branch_id'], $c['employee_id']);
        $today = date('Y-m-d');
        $bills = Gate::allows('outstanding.view', $user) ? Database::fetchAll(
            'SELECT ob.invoice_no, ob.invoice_date, ob.due_date, ob.bill_amount, ob.balance, DATEDIFF(?, ' . OpenBills::ageColumn('ob') . ') AS age
             FROM ' . OpenBills::sql() . ' ob WHERE ob.customer_id = ? ORDER BY ob.invoice_date, ob.invoice_no', [$today, $c['id']]) : [];
        foreach ($bills as &$b) {
            $b['bill_amount'] = self::r(Money::fromDb($b['bill_amount']));
            $b['balance'] = self::r(Money::fromDb($b['balance']));
            $b['age'] = (int) $b['age'];
        }
        unset($b);
        $invoices = Gate::allows('sales.view', $user) ? Database::fetchAll(
            'SELECT invoice_no, invoice_date, document_type, taxable_value, total_value FROM v_sales_documents WHERE customer_id = ? ORDER BY invoice_date DESC, invoice_id DESC LIMIT 10', [$c['id']]) : [];
        $receipts = Gate::allows('collections.view', $user) ? Database::fetchAll(
            'SELECT receipt_no, receipt_date, amount, payment_mode FROM v_valid_collections WHERE customer_id = ? ORDER BY receipt_date DESC, collection_id DESC LIMIT 10', [$c['id']]) : [];
        Response::json(['success' => true, 'data' => [
            'customer' => $c + ['id' => (int) $c['id']],
            'outstanding_total' => self::r(array_sum(array_map(static fn ($b) => Money::parse($b['balance']), $bills))),
            'open_bills' => $bills, 'invoices' => $invoices, 'receipts' => $receipts,
        ]]);
    }

    /** GET /api/leads?status=open|won|lost|all */
    public static function leads(): void
    {
        [$w, $p] = DataScope::for(Auth::user())->where('l.branch_id', 'l.employee_id');
        $status = (string) ($_GET['status'] ?? 'open');
        $w .= match ($status) {
            'won' => " AND l.status = 'won'", 'lost' => " AND l.status = 'lost'", 'all' => '', default => " AND l.status NOT IN ('won','lost')",
        };
        $rows = Database::fetchAll(
            "SELECT l.id, l.lead_number, l.name, l.company_name, l.mobile, l.status, l.priority, l.expected_value, l.next_followup_at,
                    s.name AS source, e.short_name AS employee
             FROM leads l LEFT JOIN lead_sources s ON s.id = l.source_id LEFT JOIN employees e ON e.id = l.employee_id
             WHERE l.deleted_at IS NULL AND {$w} ORDER BY l.next_followup_at IS NULL, l.next_followup_at, l.id DESC LIMIT 100",
            $p
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        Response::json(['success' => true, 'data' => ['leads' => $rows]]);
    }

    /** GET /api/followups/due - pending follow-ups due today or earlier, for leads and customers in scope. */
    public static function followups(): void
    {
        [$w, $p] = DataScope::for(Auth::user())->where('x.branch_id', 'x.employee_id');
        $rows = Database::fetchAll(
            "SELECT x.* FROM (
                 SELECT fu.id, fu.followup_at, fu.followup_type, fu.notes, fu.lead_id, fu.customer_id,
                        COALESCE(fu.employee_id, l.employee_id, c.employee_id) AS employee_id, COALESCE(l.branch_id, c.branch_id) AS branch_id,
                        COALESCE(l.name, c.name) AS name, COALESCE(l.mobile, c.mobile) AS mobile, COALESCE(l.lead_number, c.customer_code) AS ref
                 FROM followups fu LEFT JOIN leads l ON l.id = fu.lead_id LEFT JOIN customers c ON c.id = fu.customer_id
                 WHERE fu.status = 'pending' AND fu.deleted_at IS NULL AND fu.followup_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
             ) x WHERE {$w} ORDER BY x.followup_at LIMIT 100",
            $p
        );
        foreach ($rows as &$r) {
            unset($r['branch_id'], $r['employee_id']);
            $r['id'] = (int) $r['id'];
        }
        Response::json(['success' => true, 'data' => ['followups' => $rows]]);
    }

    private static function r(?int $paise): ?string
    {
        return $paise === null ? null : Money::toDecimal($paise);
    }

    /** @param array<int, string> $opts @return list<array{id: int, label: string}> */
    private static function options(array $opts): array
    {
        return array_map(static fn ($id, $label) => ['id' => (int) $id, 'label' => $label], array_keys($opts), $opts);
    }
}
