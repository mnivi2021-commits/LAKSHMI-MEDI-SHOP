<?php

declare(strict_types=1);

/*
 * Reports (Phase 20):  php tests/reports.php
 * Every report runs, and report totals equal the dashboard KPI for the same
 * period / as-on date / filters. Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\CollectionKpi;
use App\Modules\Dashboard\Kpi\OutstandingKpi;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;
use App\Modules\Dashboard\Kpi\SalesKpi;
use App\Modules\Dashboard\Kpi\SampleDcKpi;
use App\Modules\Reports\ReportController;
use App\Modules\Reports\ReportFilters;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $admin = seeded_user('admin');
    $reports = ReportController::available($admin);
    check('admin sees all 15 reports', 15, count($reports));
    $run = static function (string $key, array $q = [], ?array $user = null) use ($reports, $today, $admin): array {
        $f = new ReportFilters($user ?? $admin, $q, $today);
        $rows = $reports[$key]->rows($f);
        return [$rows, $reports[$key]->totals($rows), $f];
    };
    foreach (array_keys($reports) as $key) {
        [$rows] = $run($key);
        check("{$key} runs" . ($key === 'sms-usage' ? ' (no SMS in seed data)' : ' and returns rows'), true, $key === 'sms-usage' ? $rows === [] : count($rows) > 0);
    }

    $ctx = DashboardContext::fromRequest($admin, [], $today);

    // ------------------------------------------------------------- sales
    $sales = (new SalesKpi($ctx))->summary();
    [, $t] = $run('sales-register');
    check('sales register taxable (FY to date) = dashboard sales', $sales['sales_total'], $t['taxable']);
    [, $t] = $run('sales-by-customer');
    check('sales by customer = dashboard sales', $sales['sales_total'], $t['taxable']);
    [, $t] = $run('sales-by-product');
    $lines = (int) round((float) Database::value("SELECT SUM(taxable_value) * 100 FROM v_sales_lines WHERE invoice_date BETWEEN '2026-04-01' AND '2026-10-06'"));
    check('sales by product = all product lines', $lines, $t['taxable']);
    check('sales by product has no quantity total (mixed units)', false, array_key_exists('qty', $t));

    [, $t] = $run('sales-register', ['from' => '2026-10-01', 'to' => '2026-10-06']);
    check('month-to-date register = dashboard month to date', $sales['sales_month_to_previous_day'] + $sales['sales_today'], $t['taxable']);

    $ctxCust = DashboardContext::fromRequest($admin, ['customer' => '1'], $today);
    [, $t] = $run('sales-register', ['customer' => 'CUS-00001']);
    check('customer filter = dashboard with customer filter', (new SalesKpi($ctxCust))->summary()['sales_total'], $t['taxable']);

    // ------------------------------------------------------------ targets
    [$rows, $t] = $run('target-achievement');
    $tgt = (int) round((float) Database::value("SELECT SUM(sales_target) * 100 FROM sales_targets WHERE target_month BETWEEN '2026-04-01' AND '2026-10-06'"));
    check('target report: targets for Apr-Oct', $tgt, $t['sales_target']);
    $assigned = (int) round((float) Database::value("SELECT SUM(taxable_value) * 100 FROM v_sales_documents WHERE invoice_date BETWEEN '2026-04-01' AND '2026-10-06' AND employee_id IS NOT NULL"));
    check('target report: sales of assigned invoices', $assigned, $t['sales']);
    check('target report: achieved % per row', true, array_reduce($rows, static fn ($ok, $r) => $ok && ($r['sales_target'] === 0 || abs($r['sales_pct'] - round($r['sales'] * 100 / $r['sales_target'], 1)) < 0.001), true));

    // --------------------------------------------------------- collection
    [, $t] = $run('collection-register');
    check('collection register = dashboard FY collection', (new CollectionKpi($ctx))->summary()['fy_to_date'], $t['amount']);
    check('adjusted + on account = amount', $t['amount'], $t['allocated'] + $t['on_account']);

    // -------------------------------------------------------- outstanding
    $o = (new OutstandingKpi($ctx))->categories();
    [$rows, $t] = $run('outstanding-ageing');
    check('ageing total = dashboard outstanding', array_sum(array_column($o, 'value')), $t['total']);
    check('ageing 91-150 = dashboard 90 DAYS box', $o['d90']['value'], $t['b_91-150']);
    check('ageing 150+ = dashboard 150 DAYS box', $o['d150']['value'], $t['b_150+']);
    check('ageing buckets add up to total per row', true, array_reduce($rows, static fn ($ok, $r) => $ok && $r['b_0-30'] + $r['b_31-60'] + $r['b_61-90'] + $r['b_91-150'] + $r['b_150+'] === $r['total'], true));
    $june = DashboardContext::fromRequest($admin, ['month' => '2026-06'], $today);
    [, $t] = $run('outstanding-ageing', ['as_on' => '2026-06-30']);
    check('ageing as on 30-06-2026 = dashboard for June', (new OutstandingKpi($june))->total(), $t['total']);

    // ------------------------------------------------------ orders / supply
    [, $t] = $run('pending-orders');
    check('pending orders = dashboard pending value', (new PendingOrderKpi($ctx))->summary()['value'], $t['value']);
    $sd = (new SampleDcKpi($ctx))->summary();
    [, $t] = $run('pending-samples-dc');
    check('pending samples + DC = dashboard', $sd['samples']['value'] + $sd['dc']['value'], $t['value']);

    // -------------------------------------------------------------- scope
    $jana = seeded_user('jana');
    $janaReports = ReportController::available($jana);
    check('JANA: no reports.view -> no reports', 0, count($janaReports));
    Database::query("INSERT INTO role_permissions (role_id, permission_id) SELECT 4, id FROM permissions WHERE slug = 'reports.view'");
    \App\Core\Gate::forget();
    $jana = seeded_user('jana');
    $janaReports = ReportController::available($jana);
    check('JANA with reports.view: only her modules (no SMS, mail allowed)', true, isset($janaReports['sales-register']) && !isset($janaReports['sms-usage']));
    $jctx = DashboardContext::fromRequest($jana, [], $today);
    $f = new ReportFilters($jana, [], $today);
    $rows = $janaReports['sales-register']->rows($f);
    check('JANA sales register = her dashboard', (new SalesKpi($jctx))->summary()['sales_total'], $janaReports['sales-register']->totals($rows)['taxable']);
    check('JANA cannot filter on a Coimbatore customer', ['Customer CUS-00006 was not found in your customers.'], (new ReportFilters($jana, ['customer' => 'CUS-00006'], $today))->notices);

    // ------------------------------------------------------------ filters
    $f = new ReportFilters($admin, ['from' => '06-10-2026', 'to' => '01-10-2026'], $today);
    check('reversed dates swapped', ['2026-10-01', '2026-10-06'], [$f->from, $f->to]);
    check('future as-on clamped to today', '2026-10-06', (new ReportFilters($admin, ['as_on' => '2027-01-01'], $today))->asOn);
    // ---------------------------------------------- rep-wise details (Report menu)
    [, $t] = $run('sales-details');
    check('sales details lines = sales by product', $run('sales-by-product')[1]['taxable'], $t['value']);
    [$rows, $t] = $run('sales-details', ['employee' => '2']);
    check('sales details for JANA only', ['JANA'], array_values(array_unique(array_column($rows, 'rep'))));
    check('sales details price x qty ~ value (no discount in seed)', true, array_reduce($rows, static fn ($ok, $r) => $ok && abs((int) round($r['price'] * (float) $r['qty']) - $r['value']) <= 100 * max(1, abs((float) $r['qty'])), true));
    [$rows, $t] = $run('target-commitment');
    check('target commitment: targets = target report', $run('target-achievement')[1]['sales_target'], $t['target']);
    check('target commitment: Closed exactly when achieved >= target', true, array_reduce($rows, static fn ($ok, $r) => $ok && ($r['status'] === 'Closed') === ($r['target'] > 0 && $r['achieved'] >= $r['target']), true));
    check('target commitment: balance = target - achieved (not below 0)', true, array_reduce($rows, static fn ($ok, $r) => $ok && $r['balance'] === max(0, $r['target'] - $r['achieved']), true));
    [$rows, $t] = $run('payment-pending');
    check('payment pending total = ageing total', $run('outstanding-ageing')[1]['total'], $t['balance']);
    [$rows] = $run('payment-pending', ['employee' => '2']);
    check('payment pending for JANA only', ['JANA'], array_values(array_unique(array_column($rows, 'rep'))));
    [$rows] = $run('pending-orders');
    check('pending orders show rep and price', true, $rows !== [] && $rows[0]['rep'] !== '' && $rows[0]['price'] > 0);
    [$rows] = $run('pending-samples-dc');
    check('samples / DC show rep', true, $rows !== [] && $rows[0]['rep'] !== '');
} finally {
    $pdo->rollBack();
}

exit(test_summary());
