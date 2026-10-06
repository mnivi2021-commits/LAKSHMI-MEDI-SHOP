<?php

declare(strict_types=1);

/*
 * Sales Details grid (Phase 13) - accuracy tests:  php tests/sales_grid.php
 * The grid's totals row must equal the dashboard KPIs for the same filters,
 * and each row must equal the KPI filtered to that employee / branch / month.
 * Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\CollectionKpi;
use App\Modules\Dashboard\Kpi\OutstandingKpi;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;
use App\Modules\Dashboard\Kpi\SalesKpi;
use App\Modules\Dashboard\Kpi\SampleDcKpi;
use App\Modules\Sales\SalesGrid;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

/** KPI figures for one context, in the grid's column names. */
$kpis = static function (DashboardContext $ctx): array {
    $s = (new SalesKpi($ctx))->summary();
    $o = (new OutstandingKpi($ctx))->categories();
    $sd = (new SampleDcKpi($ctx))->summary();
    return [
        'target'      => $s['annual_target'],
        'sales'       => $s['sales_total'],
        'collection'  => (new CollectionKpi($ctx))->summary()['fy_to_date'],
        'pending'     => (new PendingOrderKpi($ctx))->summary()['value'],
        'samples'     => $sd['samples']['value'],
        'dc'          => $sd['dc']['value'],
        'outstanding' => array_sum(array_column($o, 'value')),
        'd90'         => $o['d90']['value'],
        'd150'        => $o['d150']['value'],
    ];
};

try {
    $admin = seeded_user('admin');

    // --- Totals = dashboard, for each grouping --------------------------------------
    $ctx = DashboardContext::fromRequest($admin, [], $today);
    $expected = $kpis($ctx);
    foreach (['employee', 'branch'] as $by) {
        $g = (new SalesGrid($ctx))->build($by);
        foreach (SalesGrid::COLUMNS as $col) {
            check("by {$by}: total {$col} = dashboard", $expected[$col], $g['totals'][$col]);
        }
    }
    check('sanity: there are sales, collections, pending and outstanding', true,
        $expected['sales'] > 0 && $expected['collection'] > 0 && $expected['pending'] > 0 && $expected['outstanding'] > 0);

    $m = (new SalesGrid($ctx))->build('month');
    check('by month: only flow columns', ['target', 'sales', 'collection'], $m['columns']);
    foreach (['target', 'sales', 'collection'] as $col) {
        check("by month: total {$col} = dashboard", $expected[$col], $m['totals'][$col]);
    }
    check('by month: 12 rows (targets cover the whole FY)', 12, count($m['rows']));
    check('by month: first row is April', '2026-04', $m['rows'][0]['key']);
    check('by month: future months have no sales', 0, $m['rows'][11]['sales']);

    // --- Each row = KPI filtered to that row ---------------------------------------
    $byEmp = (new SalesGrid($ctx))->build('employee');
    $checked = 0;
    foreach ($byEmp['rows'] as $row) {
        if ((int) $row['key'] === 0) {
            continue;
        }
        $rowCtx = DashboardContext::fromRequest($admin, ['employee' => (string) $row['key']], $today);
        if ($rowCtx->filters['employee_id'] === null) {
            continue;                       // not a selectable sales rep (e.g. a manager with old invoices)
        }
        $k = $kpis($rowCtx);
        foreach (SalesGrid::COLUMNS as $col) {
            check("employee {$row['label']}: {$col}", $k[$col], $row[$col]);
        }
        $checked++;
    }
    check('several employee rows were cross-checked', true, $checked >= 4);

    $byBranch = (new SalesGrid($ctx))->build('branch');
    foreach ($byBranch['rows'] as $row) {
        $k = $kpis(DashboardContext::fromRequest($admin, ['branch' => (string) $row['key']], $today));
        foreach (['target', 'sales', 'collection', 'outstanding'] as $col) {
            check("branch {$row['label']}: {$col}", $k[$col], $row[$col]);
        }
    }

    // Month row = SalesKpi monthly() for that month.
    $monthly = array_column((new SalesKpi($ctx))->monthly(), 'sales', 'month');
    foreach ($m['rows'] as $row) {
        if ($row['key'] <= '2026-10') {
            check("month {$row['key']}: sales = SalesKpi monthly", $monthly[$row['key']] ?? 0, $row['sales']);
        }
    }

    // --- Achieved % ---------------------------------------------------------------
    $t = $byEmp['totals'];
    check('total achieved % = sales / target', round($t['sales'] * 100 / $t['target'], 1), $t['achieved_pct']);

    // --- Past month as-on --------------------------------------------------------
    $june = DashboardContext::fromRequest($admin, ['month' => '2026-06'], $today);
    $gj = (new SalesGrid($june))->build('employee');
    $ej = $kpis($june);
    foreach (['sales', 'collection', 'pending', 'outstanding'] as $col) {
        check("as on 30-06-2026: total {$col} = dashboard", $ej[$col], $gj['totals'][$col]);
    }

    // --- Scope: JANA sees only herself; permission-limited columns -----------------
    $jana = seeded_user('jana');
    $janaId = (int) Database::value("SELECT id FROM employees WHERE short_name = 'JANA'");
    $jctx = DashboardContext::fromRequest($jana, [], $today);
    $gjana = (new SalesGrid($jctx))->build('employee');
    check('JANA grid has one row', 1, count($gjana['rows']));
    check('JANA grid row is herself', $janaId, (int) $gjana['rows'][0]['key']);
    check('JANA grid sales = her dashboard', $kpis($jctx)['sales'], $gjana['totals']['sales']);

    $limited = (new SalesGrid($ctx, ['sales', 'collection']))->build('employee');
    check('allowed columns only', ['sales', 'collection'], $limited['columns']);
    check('no outstanding key when not allowed', false, array_key_exists('outstanding', $limited['totals']));
    check('achieved % null without target column', null, $limited['totals']['achieved_pct']);

    // --- A new invoice moves the grid and the dashboard identically -----------------
    $before = (new SalesGrid($ctx))->build('employee')['totals']['sales'];
    $cust = Database::fetch('SELECT id, branch_id, employee_id FROM customers WHERE employee_id = ? LIMIT 1', [$janaId]);
    Database::query(
        "INSERT INTO sales_invoices (financial_year_id, invoice_no, invoice_date, due_date, customer_id, branch_id, employee_id, taxable_amount, tax_amount, total_amount, source, created_by)
         VALUES (?, 'TEST-GRID-1', '2026-10-06', '2026-11-05', ?, ?, ?, 12345.67, 2222.22, 14567.89, 'manual', 1)",
        [$ctx->fyRow['id'], $cust['id'], $cust['branch_id'], $cust['employee_id']]
    );
    $after = (new SalesGrid($ctx))->build('employee');
    check('new invoice adds exactly its taxable value', $before + 1234567, $after['totals']['sales']);
    check('dashboard agrees after the new invoice', $kpis($ctx)['sales'], $after['totals']['sales']);
} finally {
    $pdo->rollBack();
}

exit(test_summary());
