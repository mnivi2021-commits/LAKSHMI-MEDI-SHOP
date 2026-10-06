<?php

declare(strict_types=1);

/*
 * Section C - Customer / Product drill-down accuracy tests:
 *   php tests/customer_product_panel.php
 * The panel must show EXACTLY what each KPI class reports for that same
 * customer/product and period - it is a composition, not a separate
 * calculation. Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Core\Money;
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
    // Customer 1 = Sri Balaji Traders (CHN, JANA) - has invoices, an overdue 150+ bill, a payment.
    $custId = (int) Database::value("SELECT id FROM customers WHERE customer_code = 'CUS-00001'");
    check('found the seeded customer', 'Sri Balaji Traders', Database::value('SELECT name FROM customers WHERE id = ?', [$custId]));

    $ctx = DashboardContext::fromRequest($admin, ['customer' => (string) $custId], $today);
    $panel = DashboardController::customerPanel($ctx, $custId);

    $sales = (new SalesKpi($ctx))->summary();
    check('panel sales = SalesKpi (customer filter applied)', $sales['sales_total'], $panel['sales']['sales_total']);
    check('customer filter: no annual target', null, $panel['sales']['annual_target']);

    $collection = (new CollectionKpi($ctx))->summary();
    check('panel collection = CollectionKpi', $collection['total'], $panel['collection']['total']);
    check('panel collection overdue = CollectionKpi overdue', $collection['overdue'], $panel['collection']['overdue']);

    $pending = (new PendingOrderKpi($ctx))->summary();
    check('panel pending = PendingOrderKpi', $pending['value'], $panel['pending']['value']);

    $sampleDc = (new SampleDcKpi($ctx))->summary();
    check('panel sample/dc = SampleDcKpi', $sampleDc, $panel['sampleDc']);

    $outstanding = (new OutstandingKpi($ctx))->categories();
    check('panel outstanding = OutstandingKpi', $outstanding, $panel['outstanding']);

    // Independent last order / last payment.
    $rawLastOrder = Database::value("SELECT MAX(invoice_date) FROM sales_invoices WHERE customer_id = ? AND document_type = 'invoice' AND status = 'active' AND deleted_at IS NULL", [$custId]);
    $rawLastPayment = Database::value("SELECT MAX(receipt_date) FROM collections WHERE customer_id = ? AND status IN ('received','cleared') AND deleted_at IS NULL", [$custId]);
    check('last order date', $rawLastOrder, $panel['last_order']);
    check('last payment date', $rawLastPayment, $panel['last_payment']);
    check('customer identity shown', 'CUS-00001', $panel['customer']['customer_code']);
    check('customer branch shown', 'Chennai Head Office', $panel['customer']['branch']);

    // A customer outside the acting user's scope is rejected by DashboardContext (checked
    // already in dashboard.php tests); here we confirm the panel composes correctly once in scope.
    $jana = seeded_user('jana');
    $janaCtx = DashboardContext::fromRequest($jana, ['customer' => (string) $custId], $today);
    check('JANA may select her own customer', $custId, $janaCtx->filters['customer_id']);
    $otherCustId = (int) Database::value("SELECT id FROM customers WHERE customer_code = 'CUS-00006'");   // MUKESH's customer, different branch
    $janaCtx2 = DashboardContext::fromRequest($jana, ['customer' => (string) $otherCustId], $today);
    check('JANA cannot select a customer outside her scope', null, $janaCtx2->filters['customer_id']);
    check('...and is told why', true, count($janaCtx2->notices) > 0);

    // --- Product panel ------------------------------------------------------------------
    // Product 3 = BOPP Tape, sold across multiple invoices/employees in the seed.
    $prodId = (int) Database::value("SELECT id FROM products WHERE product_code = 'P003'");
    $pctx = DashboardContext::fromRequest($admin, ['product' => (string) $prodId], $today);
    $ppanel = DashboardController::productPanel($pctx, $prodId);

    $psales = (new SalesKpi($pctx))->summary();
    check('product panel sales = SalesKpi (line-level)', $psales['sales_total'], $ppanel['sales']['sales_total']);

    $rawQty = Database::value(
        "SELECT COALESCE(SUM(CASE si.document_type WHEN 'credit_note' THEN -sii.quantity ELSE sii.quantity END), 0)
         FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id
         WHERE sii.product_id = ? AND si.status = 'active' AND si.deleted_at IS NULL
           AND si.invoice_date BETWEEN '2026-04-01' AND '2026-10-06'",
        [$prodId]
    );
    check('quantity sold matches independent SQL', rtrim(rtrim((string) $rawQty, '0'), '.'), $ppanel['quantity_sold']);

    $rawCustomers = (int) Database::value(
        "SELECT COUNT(DISTINCT si.customer_id) FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id
         WHERE sii.product_id = ? AND si.status = 'active' AND si.deleted_at IS NULL AND si.invoice_date BETWEEN '2026-04-01' AND '2026-10-06'",
        [$prodId]
    );
    check('distinct customers matches independent SQL', $rawCustomers, $ppanel['customers']);

    $ppending = (new PendingOrderKpi($pctx))->summary();
    check('product panel pending = PendingOrderKpi (line filter)', $ppending['value'], $ppanel['pending']['value']);
    check('product identity shown', 'P003', $ppanel['product']['product_code']);

    $byEmp = (new SalesKpi($pctx))->breakdown('employee');
    check('product panel employee breakdown = SalesKpi breakdown', $byEmp, $ppanel['byEmployee']);
    check('employee breakdown sums to product total', $psales['sales_total'], array_sum(array_column($byEmp, 'fy_to_date')));

    // Collection/Outstanding correctly report "not applicable" for a product (shared logic).
    check('collection not applicable per product', false, (new CollectionKpi($pctx))->applies());
    check('outstanding not applicable per product', false, (new OutstandingKpi($pctx))->applies());
} finally {
    $pdo->rollBack();
}

exit(test_summary());
