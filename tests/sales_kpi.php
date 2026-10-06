<?php

declare(strict_types=1);

/*
 * Step A1 Sales Performance - accuracy tests:  php tests/sales_kpi.php
 * Every KPI is compared with an independent raw-SQL calculation on the base
 * tables (NOT the views the KPI uses). Seeded demo DB; rolled back at the end.
 * "Today" is fixed to 06-10-2026.
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\SalesKpi;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$passed = 0;
$failed = 0;
function check(string $name, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

/** Independent: net taxable sales from base tables (invoices minus credit notes, active, not deleted). */
function raw(string $from, string $to, string $extra = '', array $params = [], string $col = 'taxable_amount'): int
{
    $v = Database::value(
        "SELECT COALESCE(SUM(CASE document_type WHEN 'credit_note' THEN -{$col} ELSE {$col} END), 0)
         FROM sales_invoices WHERE status = 'active' AND deleted_at IS NULL AND invoice_date BETWEEN ? AND ? {$extra}",
        array_merge([$from, $to], $params)
    );
    return Money::fromDb($v);
}

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $uid = static fn (string $u): int => (int) Database::value('SELECT id FROM users WHERE username = ?', [$u]);
    $admin = Auth::loadUser($uid('admin'));
    $jana = Auth::loadUser($uid('jana'));
    $kpi = static fn (array $user, array $q = []): SalesKpi => new SalesKpi(DashboardContext::fromRequest($user, $q, $today));

    // --- Company-wide, as on 06-10-2026 --------------------------------------------
    $s = $kpi($admin)->summary();
    check('annual target = sum of 60 monthly targets', Money::fromDb(Database::value('SELECT SUM(sales_target) FROM sales_targets WHERE financial_year_id = 2')), $s['annual_target']);
    check('annual target = 45,60,000', 456000000, $s['annual_target']);
    check('FY to previous day (01-04..05-10)', raw('2026-04-01', '2026-10-05'), $s['sales_fy_to_previous_day']);
    check('October to previous day (01-10..05-10)', raw('2026-10-01', '2026-10-05'), $s['sales_month_to_previous_day']);
    check("today's sales (06-10)", raw('2026-10-06', '2026-10-06'), $s['sales_today']);
    check('total = previous day + today, no double count', raw('2026-04-01', '2026-10-06'), $s['sales_total']);
    check('credit note is deducted (net of 2,000)', raw('2026-04-01', '2026-10-06', "AND document_type = 'invoice'") - 200000, $s['sales_total']);
    check('achieved % (1 dp)', round($s['sales_total'] * 100 / 456000000, 1), $s['achieved_pct']);
    check('target pending', 456000000 - $s['sales_total'], $s['target_pending']);
    check('6 completed months (Apr-Sep)', 6, $s['completed_months']);
    check('average monthly = Apr-Sep / 6', intdiv(raw('2026-04-01', '2026-09-30') + 3, 6), $s['average_monthly']);
    check('6 remaining months (Oct-Mar)', 6, $s['remaining_months']);
    check('required monthly = pending / 6', intdiv($s['target_pending'] + 3, 6), $s['required_monthly']);
    check('periods labelled', ['01-04-2026 to 05-10-2026', '01-10-2026 to 05-10-2026', '06-10-2026'], array_values($s['periods']));

    // --- Every drill-down adds up to the card ------------------------------------------
    $k = $kpi($admin);
    $monthly = $k->monthly();
    check('12 months returned', 12, count($monthly));
    check('month-wise sales sum = total', $s['sales_total'], array_sum(array_map(static fn ($m) => (int) $m['sales'], $monthly)));
    check('month-wise targets sum = annual target', $s['annual_target'], array_sum(array_column($monthly, 'target')));
    check('future months have no sales value', null, $monthly[11]['sales']);
    check('branch breakdown sums to total', $s['sales_total'], array_sum(array_column($k->breakdown('branch'), 'fy_to_date')));
    check('employee breakdown sums to total', $s['sales_total'], array_sum(array_column($k->breakdown('employee'), 'fy_to_date')));
    check('branch targets sum to annual target', $s['annual_target'], array_sum(array_column($k->breakdown('branch'), 'target')));
    $ctx = DashboardContext::fromRequest($admin, [], $today);
    $docs = $k->documents($ctx->windows()['fy_to_date'], 1000, 0);
    check('document list total = card total', $s['sales_total'], $docs['total']);
    check('document count = FY documents', $s['documents_fy'], $docs['count']);
    check("today's list = today's sales", $s['sales_today'], $k->documents($ctx->windows()['today'], 100, 0)['total']);

    // --- Data scope: JANA sees only JANA --------------------------------------------------
    $j = $kpi($jana)->summary();
    check('JANA target = 12 x 90,000', 108000000, $j['annual_target']);
    check('JANA total = employee 2 only', raw('2026-04-01', '2026-10-06', 'AND employee_id = 2'), $j['sales_total']);
    check('JANA cannot widen scope via filter', $j['sales_total'], $kpi($jana, ['employee' => '3', 'branch' => '2'])->summary()['sales_total']);

    // --- Filters ----------------------------------------------------------------------------
    $b = $kpi($admin, ['branch' => '2'])->summary();
    check('branch filter: Coimbatore sales', raw('2026-04-01', '2026-10-06', 'AND branch_id = 2'), $b['sales_total']);
    check('branch filter: Coimbatore target (PRAKASH + ANANTH)', 12 * (7500000 + 7000000), $b['annual_target']);

    $c = $kpi($admin, ['customer' => '4'])->summary();
    check('customer filter sales', raw('2026-04-01', '2026-10-06', 'AND customer_id = 4'), $c['sales_total']);
    check('customer filter: target not applicable', null, $c['annual_target']);
    check('customer filter: no achieved %', null, $c['achieved_pct']);

    $p = $kpi($admin, ['product' => '3'])->summary();
    $rawProduct = Money::fromDb(Database::value(
        "SELECT COALESCE(SUM(CASE s.document_type WHEN 'credit_note' THEN -i.taxable_amount ELSE i.taxable_amount END), 0)
         FROM sales_invoice_items i JOIN sales_invoices s ON s.id = i.invoice_id
         WHERE s.status = 'active' AND s.deleted_at IS NULL AND i.product_id = 3 AND s.invoice_date BETWEEN '2026-04-01' AND '2026-10-06'"
    ));
    check('product filter uses line items', $rawProduct, $p['sales_total']);

    // --- Past month: as on 31-08-2026 --------------------------------------------------------
    $a = $kpi($admin, ['month' => '2026-08'])->summary();
    check('Aug: FY to previous day = 01-04..30-08', raw('2026-04-01', '2026-08-30'), $a['sales_fy_to_previous_day']);
    check('Aug: month to previous day = 01-08..30-08', raw('2026-08-01', '2026-08-30'), $a['sales_month_to_previous_day']);
    check('Aug: as-on day = 31-08', raw('2026-08-31', '2026-08-31'), $a['sales_today']);
    check('Aug: total = FY to 31-08', raw('2026-04-01', '2026-08-31'), $a['sales_total']);
    check('Aug: 4 completed months', 4, $a['completed_months']);
    check('Aug: average = Apr-Jul / 4', intdiv(raw('2026-04-01', '2026-07-31') + 2, 4), $a['average_monthly']);
    check('Aug: 8 remaining months', 8, $a['remaining_months']);

    // --- Past FY: as on 31-03-2026 -----------------------------------------------------------
    $f = $kpi($admin, ['fy' => '1'])->summary();
    check('FY 2025-26 total', raw('2025-04-01', '2026-03-31'), $f['sales_total']);
    check('FY 2025-26 has no targets set', 0, $f['annual_target']);
    check('no target -> no achieved %', null, $f['achieved_pct']);

    // --- Live behaviour: new invoice today moves only "today" and "total" -------------------------
    $before = $kpi($admin)->summary();
    Database::query(
        "INSERT INTO sales_invoices (financial_year_id, invoice_no, invoice_date, customer_id, branch_id, employee_id, taxable_amount, tax_amount, total_amount)
         VALUES (2, 'T/LIVE/1', '2026-10-06', 1, 1, 2, 12345.67, 2222.22, 14567.89)"
    );
    $after = $kpi($admin)->summary();
    check('new invoice adds to today', $before['sales_today'] + 1234567, $after['sales_today']);
    check('new invoice adds to total once', $before['sales_total'] + 1234567, $after['sales_total']);
    check('previous-day figure unchanged', $before['sales_fy_to_previous_day'], $after['sales_fy_to_previous_day']);

    Database::query("UPDATE sales_invoices SET status = 'cancelled' WHERE invoice_no = 'T/LIVE/1'");
    check('cancelled invoice drops out', $before['sales_total'], $kpi($admin)->summary()['sales_total']);
    Database::query("UPDATE sales_invoices SET status = 'active', deleted_at = NOW() WHERE invoice_no = 'T/LIVE/1'");
    check('soft-deleted invoice drops out', $before['sales_total'], $kpi($admin)->summary()['sales_total']);

    // --- Basis setting: invoice total incl. GST ---------------------------------------------------
    Database::query("UPDATE settings SET setting_value = 'total' WHERE setting_group = 'finance' AND setting_key = 'sales_amount_basis'");
    Settings::forget();
    $g = $kpi($admin)->summary();
    check('basis = total uses GST-inclusive value', raw('2026-04-01', '2026-10-06', '', [], 'total_amount'), $g['sales_total']);
    check('basis reported', 'total', $g['basis']);
    Settings::forget();
} finally {
    $pdo->rollBack();
    Settings::forget();
}

echo PHP_EOL . "{$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
