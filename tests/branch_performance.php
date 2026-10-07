<?php

declare(strict_types=1);

/*
 * Branch Performance (daily entry sheets):  php tests/branch_performance.php
 * Every figure of the dashboard's first page recomputed with plain SQL.
 * Seeded DB; rolled back. "Today" = 06-10-2026 (seed entries run to that day).
 */

use App\Core\Database;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\BranchPerformance;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$sum = static fn (string $sql, array $p = []): int => Money::fromDb(Database::value($sql, $p));
$cnt = static fn (string $sql, array $p = []): int => (int) Database::value($sql, $p);

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $admin = seeded_user('admin');
    $today = new DateTimeImmutable('2026-10-06');
    $ctx = DashboardContext::fromRequest($admin, [], $today);
    $bp = new BranchPerformance($ctx);

    // ---------------------------------------------------------- 1. sales
    $s = $bp->sales();
    $t = $s['total'];
    check('rows are branches', 'branch', $s['by']);
    check('3 branch rows', 3, count($s['rows']));
    check('annual target = FY targets', $sum('SELECT SUM(sales_target) FROM sales_targets WHERE financial_year_id = 2'), $t['annual_target']);
    check('sales as on previous day = 01-04 .. 05-10', $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-04-01' AND '2026-10-05'"), $t['fy_prev']);
    check('month target = October targets', $sum("SELECT SUM(sales_target) FROM sales_targets WHERE target_month = '2026-10-01'"), $t['month_target']);
    check('this month sales = 01-10 .. 05-10', $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-05'"), $t['month_prev']);
    check('month NOB', $cnt("SELECT SUM(sales_bills) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-05'"), $t['month_nob']);
    check('month NOC', $cnt("SELECT SUM(sales_customers) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-05'"), $t['month_noc']);
    check('today sales = 06-10 only', $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE entry_date = '2026-10-06'"), $t['today']);
    check('today not inside previous-day figures', true, $t['today'] > 0 && $t['fy_prev'] + $t['today'] === $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE entry_date <= '2026-10-06'"));
    check('month % = month sales / month target x 100', round($t['month_prev'] * 100 / $t['month_target'], 1), $t['month_pct']);
    check('branch rows add up to the total', $t['fy_prev'], array_sum(array_column($s['rows'], 'fy_prev')));
    $mdu = array_values(array_filter($s['rows'], static fn ($r) => $r['label'] === 'Madurai Branch'))[0];

    // Branch selected -> its reps; the total equals that branch's row
    $c3 = DashboardContext::fromRequest($admin, ['branch' => '3'], $today);
    $s3 = (new BranchPerformance($c3))->sales();
    check('branch filter: rows are employees', 'employee', $s3['by']);
    check('branch filter: Madurai has one rep row', 1, count($s3['rows']));
    check('branch filter: same figures as the branch row', [$mdu['fy_prev'], $mdu['month_prev'], $mdu['today'], $mdu['annual_target']],
        [$s3['total']['fy_prev'], $s3['total']['month_prev'], $s3['total']['today'], $s3['total']['annual_target']]);
    $c1 = DashboardContext::fromRequest($admin, ['branch' => '1'], $today);
    check('Chennai: 2 reps (JANA, MUKESH)', 2, count((new BranchPerformance($c1))->sales()['rows']));

    // Past month: as-on = last day of September
    $sep = DashboardContext::fromRequest($admin, ['month' => '2026-09'], $today);
    $ss = (new BranchPerformance($sep))->sales()['total'];
    check('September: month sales 01-09 .. 29-09', $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-09-01' AND '2026-09-29'"), $ss['month_prev']);
    check('September: today = 30-09', $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE entry_date = '2026-09-30'"), $ss['today']);

    // ------------------------------------------------------- positions
    $latestSql = static fn (string $col, string $asOn): string =>
        "SELECT SUM(x.{$col}) FROM rep_daily_totals x
         WHERE x.entry_date = (SELECT MAX(y.entry_date) FROM rep_daily_totals y WHERE y.employee_id = x.employee_id AND y.{$col} IS NOT NULL AND y.entry_date <= '{$asOn}')";
    $pos = $bp->positions()['totals'];
    foreach (BranchPerformance::POSITIONS as $col) {
        $want = in_array($col, BranchPerformance::COUNT_POSITIONS, true) ? $cnt($latestSql($col, '2026-10-06')) : $sum($latestSql($col, '2026-10-06'));
        check("position {$col} = latest per rep", $want, $pos[$col]);
    }
    // 30-09-2026 is a Wednesday: the demo leaves positions blank, so Tuesday's figures apply
    $wed = DashboardContext::fromRequest($admin, ['month' => '2026-09'], $today);
    $pw = (new BranchPerformance($wed))->positions()['totals'];
    check('Wednesday blank -> previous day figure (pending non stock)', $sum("SELECT SUM(po_non_stock) FROM rep_daily_totals WHERE entry_date = '2026-09-29'"), $pw['po_non_stock']);
    check('Wednesday blank -> previous day figure (150 days)', $sum("SELECT SUM(os_150) FROM rep_daily_totals WHERE entry_date = '2026-09-29'"), $pw['os_150']);
    check('positions are NOT added over days', true, $pos['os_150'] < $sum("SELECT SUM(os_150) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-06'"));

    $leads = $bp->leadsThisMonth();
    check('leads this month: new customer 01-10 .. 06-10', $cnt("SELECT SUM(lead_new_customer) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-06'"), $leads['new_customer']);
    check('leads this month: new product', $cnt("SELECT SUM(lead_new_product) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-06'"), $leads['new_product']);

    // ------------------------------------------------------- collection
    $c = $bp->collection();
    check('opening outstanding = October openings', $sum("SELECT SUM(opening_outstanding) FROM rep_month_openings WHERE opening_month = '2026-10-01'"), $c['opening']);
    check('collection 01-10 .. 05-10', $sum("SELECT SUM(collection_value) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-05'"), $c['month_prev']);
    check('collection NOB / NOC', [$cnt("SELECT SUM(collection_bills) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-05'"),
        $cnt("SELECT SUM(collection_customers) FROM rep_daily_totals WHERE entry_date BETWEEN '2026-10-01' AND '2026-10-05'")], [$c['month_nob'], $c['month_noc']]);
    check('today collection = 06-10', $sum("SELECT SUM(collection_value) FROM rep_daily_totals WHERE entry_date = '2026-10-06'"), $c['today']);
    check('% of collection target', round($c['month_prev'] * 100 / $sum("SELECT SUM(collection_target) FROM sales_targets WHERE target_month = '2026-10-01'"), 1), $c['pct_target']);
    check('% of opening outstanding', round($c['month_prev'] * 100 / $c['opening'], 1), $c['pct_opening']);

    // ------------------------------------------------------------ scope
    $jana = seeded_user('jana');
    $jctx = DashboardContext::fromRequest($jana, [], $today);
    $js = (new BranchPerformance($jctx))->sales();
    check('JANA sees only her own row', 1, count($js['rows']));
    check('JANA: her sales only', $sum("SELECT SUM(sales_value) FROM rep_daily_totals WHERE employee_id = 2 AND entry_date BETWEEN '2026-04-01' AND '2026-10-05'"), $js['total']['fy_prev']);
    check('JANA: her opening only', $sum("SELECT opening_outstanding FROM rep_month_openings WHERE employee_id = 2 AND opening_month = '2026-10-01'"), (new BranchPerformance($jctx))->collection()['opening']);

    // ---------------------------------------------- pace for colouring
    check('6th of October: 16.1% of the month has passed', 16.1, $bp->periods()['month_pace']);
} finally {
    $pdo->rollBack();
}

exit(test_summary());
