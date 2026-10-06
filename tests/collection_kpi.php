<?php

declare(strict_types=1);

/*
 * Step A2 Payment Collection - accuracy tests:  php tests/collection_kpi.php
 * Compared with independent raw SQL on base tables. Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\CollectionKpi;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

function rawCollected(string $from, string $to, string $extra = '', array $p = []): int
{
    return Money::fromDb(Database::value(
        "SELECT COALESCE(SUM(amount), 0) FROM collections
         WHERE status IN ('received','cleared') AND deleted_at IS NULL AND receipt_date BETWEEN ? AND ? {$extra}",
        array_merge([$from, $to], $p)
    ));
}

/** Independent overdue: per invoice balance from base tables, due before as-on. */
function rawOverdue(string $asOn, string $extra = '', array $p = []): array
{
    $r = Database::fetch(
        "SELECT COALESCE(SUM(bal), 0) AS amt, COUNT(*) AS bills, COUNT(DISTINCT customer_id) AS customers FROM (
            SELECT i.customer_id,
                   i.total_amount
                   - COALESCE((SELECT SUM(a.amount) FROM collection_allocations a JOIN collections c ON c.id = a.collection_id
                               WHERE a.invoice_id = i.id AND c.status IN ('received','cleared') AND c.deleted_at IS NULL), 0)
                   - COALESCE((SELECT SUM(cn.total_amount) FROM sales_invoices cn WHERE cn.reference_invoice_id = i.id
                               AND cn.document_type = 'credit_note' AND cn.status = 'active' AND cn.deleted_at IS NULL), 0) AS bal,
                   COALESCE(i.due_date, DATE_ADD(i.invoice_date, INTERVAL cu.credit_days DAY)) AS due
            FROM sales_invoices i JOIN customers cu ON cu.id = i.customer_id
            WHERE i.document_type = 'invoice' AND i.status = 'active' AND i.deleted_at IS NULL {$extra}
         ) x WHERE bal > 0 AND due < ?",
        array_merge($p, [$asOn])
    );
    return [Money::fromDb($r['amt']), (int) $r['bills'], (int) $r['customers']];
}

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $admin = seeded_user('admin');
    $jana = seeded_user('jana');
    $kpi = static fn (array $u, array $q = []): CollectionKpi => new CollectionKpi(DashboardContext::fromRequest($u, $q, $today));

    $c = $kpi($admin)->summary();
    check('previous period 01-10..05-10', rawCollected('2026-10-01', '2026-10-05'), $c['previous_period']);
    check("today's collection 06-10", rawCollected('2026-10-06', '2026-10-06'), $c['today']);
    check('total = previous + today (month to date)', rawCollected('2026-10-01', '2026-10-06'), $c['total']);
    check('FY to date', rawCollected('2026-04-01', '2026-10-06'), $c['fy_to_date']);
    check('bounced cheque (12,000 on 20-09) excluded', Money::fromDb(Database::value("SELECT SUM(amount) FROM collections WHERE receipt_date BETWEEN '2026-04-01' AND '2026-10-06' AND deleted_at IS NULL")) - 1200000, $c['fy_to_date']);
    check('October collection target', Money::fromDb(Database::value("SELECT SUM(collection_target) FROM sales_targets WHERE target_month = '2026-10-01'")), $c['collection_target']);
    check('collection %', round($c['total'] * 100 / $c['collection_target'], 1), $c['collection_pct']);
    check('collection pending', max(0, $c['collection_target'] - $c['total']), $c['collection_pending']);
    check('customers this month', (int) Database::value("SELECT COUNT(DISTINCT customer_id) FROM collections WHERE status IN ('received','cleared') AND receipt_date BETWEEN '2026-10-01' AND '2026-10-06'"), $c['customers']);
    check('payments this month', (int) Database::value("SELECT COUNT(*) FROM collections WHERE status IN ('received','cleared') AND receipt_date BETWEEN '2026-10-01' AND '2026-10-06'"), $c['payments']);
    [$od, $bills, $cust] = rawOverdue('2026-10-06');
    check('overdue amount (independent bill balances)', $od, $c['overdue']);
    check('overdue bills', $bills, $c['overdue_bills']);
    check('overdue customers', $cust, $c['overdue_customers']);
    check('overdue > 0 in demo data', true, $c['overdue'] > 0);

    // Drill-downs add up.
    $k = $kpi($admin);
    $ctx = DashboardContext::fromRequest($admin, [], $today);
    check('branch breakdown sums to month total', $c['total'], array_sum(array_column($k->breakdown('branch'), 'month_to_date')));
    check('employee breakdown sums to month total', $c['total'], array_sum(array_column($k->breakdown('employee'), 'month_to_date')));
    check('receipt list (month) = card total', $c['total'], $k->receipts($ctx->windows()['month_to_date'], 1000, 0)['total']);
    check('receipt list (today) = today', $c['today'], $k->receipts($ctx->windows()['today'], 1000, 0)['total']);
    check('overdue bill list total = overdue', $c['overdue'], $k->overdueBills(1000, 0)['total']);
    check('monthly bars sum = FY to date', $c['fy_to_date'], array_sum(array_map(static fn ($m) => (int) $m['sales'], $k->monthly())));

    // Scope and filters.
    $j = $kpi($jana)->summary();
    check('JANA month total', rawCollected('2026-10-01', '2026-10-06', 'AND employee_id = 2'), $j['total']);
    check('JANA overdue = her customers\' bills', rawOverdue('2026-10-06', 'AND i.employee_id = 2')[0], $j['overdue']);
    check('JANA target = her October target', 8100000, $j['collection_target']);
    $p = $kpi($admin, ['product' => '2'])->summary();
    check('product filter: not applicable', false, $p['applies']);
    check('product filter: no figures', null, $p['total']);
    $cu = $kpi($admin, ['customer' => '4'])->summary();
    check('customer filter: overdue for customer 4', rawOverdue('2026-10-06', 'AND i.customer_id = 4')[0], $cu['overdue']);
    check('customer filter: no target', null, $cu['collection_target']);

    // Past month (as on 31-08-2026).
    $a = $kpi($admin, ['month' => '2026-08'])->summary();
    check('Aug previous period 01-08..30-08', rawCollected('2026-08-01', '2026-08-30'), $a['previous_period']);
    check('Aug as-on day 31-08', rawCollected('2026-08-31', '2026-08-31'), $a['today']);
    check('Aug target', Money::fromDb(Database::value("SELECT SUM(collection_target) FROM sales_targets WHERE target_month = '2026-08-01'")), $a['collection_target']);

    // Live: a receipt today allocated to an overdue bill moves today, total and overdue.
    $bill = Database::fetch("SELECT invoice_id, customer_id, branch_id, employee_id, balance FROM v_invoice_balances WHERE balance > 0 AND due_date < '2026-10-06' ORDER BY invoice_date LIMIT 1");
    Database::query("INSERT INTO collections (financial_year_id, receipt_no, receipt_date, customer_id, branch_id, employee_id, amount, payment_mode, status)
                     VALUES (2, 'T/RCP/1', '2026-10-06', ?, ?, ?, 1000.00, 'upi', 'cleared')", [$bill['customer_id'], $bill['branch_id'], $bill['employee_id']]);
    Database::query('INSERT INTO collection_allocations (collection_id, invoice_id, amount) VALUES (LAST_INSERT_ID(), ?, 1000.00)', [$bill['invoice_id']]);
    $after = $kpi($admin)->summary();
    check('receipt adds to today', $c['today'] + 100000, $after['today']);
    check('receipt adds to total once', $c['total'] + 100000, $after['total']);
    check('allocated receipt reduces overdue', $c['overdue'] - 100000, $after['overdue']);
    Database::query("UPDATE collections SET status = 'bounced' WHERE receipt_no = 'T/RCP/1'");
    $bounced = $kpi($admin)->summary();
    check('bounced: collection reverts', $c['total'], $bounced['total']);
    check('bounced: overdue reverts', $c['overdue'], $bounced['overdue']);

    // Imported outstanding statement.
    Database::query("INSERT INTO outstanding_bills (as_on_date, customer_id, branch_id, employee_id, invoice_no, invoice_date, due_date, bill_amount, pending_amount)
                     VALUES ('2026-10-05', 1, 1, 2, 'ERP/1', '2026-05-01', '2026-06-01', 50000, 30000),
                            ('2026-10-05', 6, 2, 4, 'ERP/2', '2026-09-30', '2026-10-30', 20000, 20000),
                            ('2026-09-30', 1, 1, 2, 'ERP/OLD', '2026-04-01', '2026-05-01', 99999, 99999)");
    Database::query("UPDATE settings SET setting_value = 'imported' WHERE setting_group = 'outstanding' AND setting_key = 'source'");
    Settings::forget();
    $imp = $kpi($admin)->summary();
    check('imported: overdue from latest snapshot only', 3000000, $imp['overdue']);
    check('imported: outstanding = latest snapshot', 5000000, $imp['outstanding']);
    check('imported: 1 overdue bill', 1, $imp['overdue_bills']);
} finally {
    $pdo->rollBack();
    Settings::forget();
}

exit(test_summary());
