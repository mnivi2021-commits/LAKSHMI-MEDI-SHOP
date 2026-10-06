<?php

declare(strict_types=1);

/*
 * 90 / 150 Day Outstanding - accuracy tests:  php tests/outstanding_kpi.php
 * Compared with independent raw SQL on base tables (not the views the KPI uses).
 * Seeded DB; rolled back. Today = 06-10-2026.
 *
 * Categories: upto90 = 0-90 days, d90 = 91-150 days ("90 DAYS" box),
 * d150 = over 150 days ("150 DAYS" box).
 */

use App\Core\Database;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\OutstandingKpi;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

/**
 * Independent bill balance from base tables only (no views).
 *
 * @return array{upto90: int, d90: int, d150: int, bills: array{upto90:int,d90:int,d150:int}}
 */
function rawOutstandingByCategory(string $asOn, string $extra = '', array $p = []): array
{
    $rows = Database::fetchAll(
        "SELECT i.invoice_date, i.total_amount,
                COALESCE((SELECT SUM(a.amount) FROM collection_allocations a JOIN collections c ON c.id = a.collection_id
                          WHERE a.invoice_id = i.id AND c.status IN ('received','cleared') AND c.deleted_at IS NULL), 0) AS paid,
                COALESCE((SELECT SUM(cn.total_amount) FROM sales_invoices cn WHERE cn.reference_invoice_id = i.id
                          AND cn.document_type = 'credit_note' AND cn.status = 'active' AND cn.deleted_at IS NULL), 0) AS credited
         FROM sales_invoices i
         WHERE i.document_type = 'invoice' AND i.status = 'active' AND i.deleted_at IS NULL {$extra}",
        $p
    );
    $cat = ['upto90' => 0, 'd90' => 0, 'd150' => 0];
    $bills = ['upto90' => 0, 'd90' => 0, 'd150' => 0];
    $asOnTs = strtotime($asOn);
    foreach ($rows as $r) {
        $bal = Money::fromDb($r['total_amount']) - Money::fromDb($r['paid']) - Money::fromDb($r['credited']);
        if ($bal <= 0) {
            continue;
        }
        $age = (int) round(($asOnTs - strtotime($r['invoice_date'])) / 86400);
        $k = $age <= 90 ? 'upto90' : ($age <= 150 ? 'd90' : 'd150');
        $cat[$k] += $bal;
        $bills[$k]++;
    }
    return ['upto90' => $cat['upto90'], 'd90' => $cat['d90'], 'd150' => $cat['d150'], 'bills' => $bills];
}

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $admin = seeded_user('admin');
    $jana = seeded_user('jana');
    $kpi = static fn (array $u, array $q = []): OutstandingKpi => new OutstandingKpi(DashboardContext::fromRequest($u, $q, $today));

    $raw = rawOutstandingByCategory('2026-10-06');
    $k = $kpi($admin);
    $cats = $k->categories();

    check('0-90 days total', $raw['upto90'], $cats['upto90']['value']);
    check('91-150 days total ("90 DAYS" box)', $raw['d90'], $cats['d90']['value']);
    check('over 150 days total ("150 DAYS" box)', $raw['d150'], $cats['d150']['value']);
    check('91-150 bill count', $raw['bills']['d90'], $cats['d90']['bills']);
    check('150+ bill count', $raw['bills']['d150'], $cats['d150']['bills']);
    check('overall total = 19,78,582 (seed)', 197858200, $k->total());
    check('categories sum to total', $k->total(), array_sum(array_column($cats, 'value')));
    check('every category has data in the seed', 3, count(array_filter($cats, static fn ($c) => $c['value'] > 0)));
    check('label: 90 DAYS = 91-150', '90 days (91-150)', $cats['d90']['label']);
    check('label: 150 DAYS = over 150', '150 days (over 150)', $cats['d150']['label']);

    // Drill-downs reconcile.
    check('all bills = total', $k->total(), $k->bills(null, 1000, 0)['total']);
    foreach (['upto90', 'd90', 'd150'] as $key) {
        check("bill list [{$key}] = category", $cats[$key]['value'], $k->bills($key, 1000, 0)['total']);
        check("bill list [{$key}] count = category bills", $cats[$key]['bills'], $k->bills($key, 1000, 0)['count']);
        $custSum = array_sum(array_column($k->customers($key), 'value'));
        check("customer list [{$key}] sums to category", $cats[$key]['value'], $custSum);
    }

    // Scope / filters.
    $j = $kpi($jana)->categories();
    $rawJ = rawOutstandingByCategory('2026-10-06', 'AND i.employee_id = 2');
    check('JANA 0-90', $rawJ['upto90'], $j['upto90']['value']);
    check('JANA 91-150', $rawJ['d90'], $j['d90']['value']);
    check('JANA 150+', $rawJ['d150'], $j['d150']['value']);

    $b2 = $kpi($admin, ['branch' => '2'])->categories();
    $rawB2 = rawOutstandingByCategory('2026-10-06', 'AND i.branch_id = 2');
    check('branch filter 91-150', $rawB2['d90'], $b2['d90']['value']);

    $product = $kpi($admin, ['product' => '3']);
    check('product filter: outstanding not applicable', false, $product->applies());
    check('product filter: no categories value', 0, $product->total());

    // Past month (as on 31-08-2026): ages measured from that date, not today.
    $aug = $kpi($admin, ['month' => '2026-08']);
    $rawAug = rawOutstandingByCategory('2026-08-31');
    $augCats = $aug->categories();
    check('as on 31-08: 91-150', $rawAug['d90'], $augCats['d90']['value']);
    check('as on 31-08: 150+', $rawAug['d150'], $augCats['d150']['value']);

    // Live behaviour: paying off a 150+ bill moves it out of that category.
    $oldBill = Database::fetch(
        "SELECT id, total_amount FROM sales_invoices WHERE document_type = 'invoice' AND status = 'active' AND deleted_at IS NULL
         AND DATEDIFF('2026-10-06', invoice_date) > 150 AND id NOT IN (SELECT invoice_id FROM collection_allocations) ORDER BY id LIMIT 1"
    );
    check('found an unpaid 150+ bill to pay off', true, $oldBill !== null);
    $totalBefore = $k->total();   // snapshot now - total() is live and would re-query after the payment below
    Database::query("INSERT INTO collections (financial_year_id, receipt_no, receipt_date, customer_id, branch_id, employee_id, amount, payment_mode, status)
                     SELECT 2, 'T/PAY150', '2026-10-06', customer_id, branch_id, employee_id, ?, 'neft', 'cleared' FROM sales_invoices WHERE id = ?",
        [$oldBill['total_amount'], $oldBill['id']]);
    Database::query('INSERT INTO collection_allocations (collection_id, invoice_id, amount) VALUES (LAST_INSERT_ID(), ?, ?)', [$oldBill['id'], $oldBill['total_amount']]);
    $after = $kpi($admin)->categories();
    check('paid-off bill leaves the 150+ category', $cats['d150']['value'] - Money::fromDb($oldBill['total_amount']), $after['d150']['value']);
    check('paid-off bill drops total outstanding', $totalBefore - Money::fromDb($oldBill['total_amount']), $kpi($admin)->total());

    // Imported outstanding statement overrides the computed view.
    Database::query("INSERT INTO outstanding_bills (as_on_date, customer_id, branch_id, employee_id, invoice_no, invoice_date, due_date, bill_amount, pending_amount) VALUES
        ('2026-10-05', 1, 1, 2, 'ERP/A90',  '2026-09-20', '2026-10-20', 40000, 40000),
        ('2026-10-05', 6, 2, 4, 'ERP/B150', '2026-04-01', '2026-05-01', 60000, 60000)");
    Database::query("UPDATE settings SET setting_value = 'imported' WHERE setting_group = 'outstanding' AND setting_key = 'source'");
    Settings::forget();
    $imp = $kpi($admin)->categories();
    check('imported: 0-90 bucket from snapshot only', 4000000, $imp['upto90']['value']);
    check('imported: 150+ bucket from snapshot only', 6000000, $imp['d150']['value']);
    check('imported: total = snapshot sum', 10000000, $kpi($admin)->total());

    // Configurable aging basis: due_date instead of invoice_date.
    Database::query("UPDATE settings SET setting_value = 'computed' WHERE setting_group = 'outstanding' AND setting_key = 'source'");
    Database::query("UPDATE settings SET setting_value = 'due_date' WHERE setting_group = 'outstanding' AND setting_key = 'aging_basis'");
    Settings::forget();
    $rawDue = rawOutstandingByCategory('2026-10-06');   // recompute using due_date for the independent check
    // Recompute independently keyed off due_date:
    $rows = Database::fetchAll(
        "SELECT i.due_date, i.total_amount,
                COALESCE((SELECT SUM(a.amount) FROM collection_allocations a JOIN collections c ON c.id = a.collection_id
                          WHERE a.invoice_id = i.id AND c.status IN ('received','cleared') AND c.deleted_at IS NULL), 0) AS paid,
                COALESCE((SELECT SUM(cn.total_amount) FROM sales_invoices cn WHERE cn.reference_invoice_id = i.id
                          AND cn.document_type = 'credit_note' AND cn.status = 'active' AND cn.deleted_at IS NULL), 0) AS credited
         FROM sales_invoices i WHERE i.document_type = 'invoice' AND i.status = 'active' AND i.deleted_at IS NULL"
    );
    $dueCat = ['upto90' => 0, 'd90' => 0, 'd150' => 0];
    foreach ($rows as $r) {
        $bal = Money::fromDb($r['total_amount']) - Money::fromDb($r['paid']) - Money::fromDb($r['credited']);
        if ($bal <= 0) {
            continue;
        }
        $age = (int) round((strtotime('2026-10-06') - strtotime($r['due_date'])) / 86400);
        $dueCat[$age <= 90 ? 'upto90' : ($age <= 150 ? 'd90' : 'd150')] += $bal;
    }
    $byDue = $kpi($admin)->categories();
    check('aging basis = due_date changes the bucketing', $dueCat['d90'], $byDue['d90']['value']);
    Settings::forget();
} finally {
    $pdo->rollBack();
    Settings::forget();
}

exit(test_summary());
