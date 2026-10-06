<?php

declare(strict_types=1);

/*
 * Sales Representative panel (Step B) - accuracy tests:  php tests/rep_panel.php
 * The panel must show EXACTLY what each KPI class/page reports for that same
 * employee and period - it is a composition, not a separate calculation.
 * Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Dashboard\Kpi\CollectionKpi;
use App\Modules\Dashboard\Kpi\OutstandingKpi;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;
use App\Modules\Dashboard\Kpi\SalesKpi;
use App\Modules\Dashboard\Kpi\SampleDcKpi;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $admin = seeded_user('admin');
    $janaId = (int) Database::value("SELECT id FROM employees WHERE short_name = 'JANA'");

    // Build the panel the same way DashboardController does: filter by employee.
    $ctx = DashboardContext::fromRequest($admin, ['employee' => (string) $janaId], $today);
    $panel = DashboardController::repPanel($ctx, $janaId);

    // Independently build each KPI for the identical context and compare.
    $sales = (new SalesKpi($ctx))->summary();
    check('panel sales total = SalesKpi total', $sales['sales_total'], $panel['sales']['sales_total']);
    check('panel annual target = SalesKpi target', $sales['annual_target'], $panel['sales']['annual_target']);
    check('panel achieved % = SalesKpi achieved %', $sales['achieved_pct'], $panel['sales']['achieved_pct']);
    check('panel month_to_date = previous-day + today', $sales['sales_month_to_previous_day'] + $sales['sales_today'], $panel['sales']['month_to_date']);

    $collection = (new CollectionKpi($ctx))->summary();
    check('panel collection total = CollectionKpi total', $collection['total'], $panel['collection']['total']);
    check('panel collection overdue = CollectionKpi overdue', $collection['overdue'], $panel['collection']['overdue']);
    check('panel collection fy_to_date = CollectionKpi fy_to_date', $collection['fy_to_date'], $panel['collection']['fy_to_date']);

    $pending = (new PendingOrderKpi($ctx))->summary();
    check('panel pending value = PendingOrderKpi value', $pending['value'], $panel['pending']['value']);
    check('panel pending oldest = PendingOrderKpi oldest', $pending['oldest'], $panel['pending']['oldest']);
    check('panel pending aging = PendingOrderKpi aging', $pending['aging'], $panel['pending']['aging']);

    $sampleDc = (new SampleDcKpi($ctx))->summary();
    check('panel samples = SampleDcKpi samples', $sampleDc['samples'], $panel['sampleDc']['samples']);
    check('panel dc = SampleDcKpi dc', $sampleDc['dc'], $panel['sampleDc']['dc']);

    $outstanding = (new OutstandingKpi($ctx))->categories();
    check('panel outstanding = OutstandingKpi categories', $outstanding, $panel['outstanding']);

    // Leads: independent raw count.
    $rawLeads = Database::fetch(
        "SELECT COUNT(CASE WHEN created_at >= '2026-10-01 00:00:00' THEN 1 END) AS new_this_month,
                COUNT(CASE WHEN status NOT IN ('won','lost') THEN 1 END) AS open_leads,
                COUNT(CASE WHEN status NOT IN ('won','lost') AND next_followup_at <= '2026-10-06 23:59:59' THEN 1 END) AS followups_due
         FROM leads WHERE deleted_at IS NULL AND employee_id = ?",
        [$janaId]
    );
    check('leads new this month', (int) $rawLeads['new_this_month'], $panel['leads']['new_this_month']);
    check('leads open', (int) $rawLeads['open_leads'], $panel['leads']['open_leads']);
    check('leads follow-ups due', (int) $rawLeads['followups_due'], $panel['leads']['followups_due']);

    // Employee identity fields.
    check('panel shows the right employee', 'Janakiraman S', $panel['employee']['name']);
    check('panel short name', 'JANA', $panel['employee']['short_name']);
    check('panel branch', 'Chennai Head Office', $panel['employee']['branch']);

    // A user restricted to one employee (JANA herself) gets that panel automatically,
    // with no explicit employee filter needed - checked via the controller's auto-select logic:
    $janaUser = seeded_user('jana');
    $janaCtx = DashboardContext::fromRequest($janaUser, [], $today);   // no employee filter in the URL
    $onlyOption = $janaCtx->employeeOptions(null);
    check('JANA has exactly one selectable employee (herself)', [$janaId], array_keys($onlyOption));

    // Auto-select mirrors what DashboardController::index() does: single option -> own panel.
    $autoRepId = count($onlyOption) === 1 ? (int) array_key_first($onlyOption) : null;
    check('auto-selected rep id = JANA', $janaId, $autoRepId);
    $janaPanel = DashboardController::repPanel($janaCtx, $autoRepId);
    check('JANA auto panel sales = her own SalesKpi', (new SalesKpi($janaCtx))->summary()['sales_total'], $janaPanel['sales']['sales_total']);

    // Admin (sees everyone) must NOT get an auto-selected panel - too many options.
    $adminCtx = DashboardContext::fromRequest($admin, [], $today);
    check('admin has more than one employee option (no auto-select)', true, count($adminCtx->employeeOptions(null)) > 1);
} finally {
    $pdo->rollBack();
}

exit(test_summary());
