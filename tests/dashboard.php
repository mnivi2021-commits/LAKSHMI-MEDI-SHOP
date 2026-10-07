<?php

declare(strict_types=1);

/*
 * Dashboard framework tests:  php tests/dashboard.php
 * Needs the seeded demo database. Runs in a transaction that is rolled back.
 * "Today" is fixed to 06-10-2026 so results do not depend on the real date.
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\QuickAddService;

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

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $uid = static fn (string $u): int => (int) Database::value('SELECT id FROM users WHERE username = ?', [$u]);
    $admin = Auth::loadUser($uid('admin'));
    $coord = Auth::loadUser($uid('coordinator'));
    $jana  = Auth::loadUser($uid('jana'));
    $ctx = static fn (array $user, array $q): DashboardContext => DashboardContext::fromRequest($user, $q, $today);

    // --- As-on date rules ----------------------------------------------------------
    $c = $ctx($admin, []);
    check('default FY is current', 'FY 2026-27', $c->fyRow['label']);
    check('default as-on is today', '2026-10-06', $c->asOn->format('Y-m-d'));
    check('today is live', true, $c->isLive);
    check('FY to previous day window', '01-04-2026 to 05-10-2026', $c->windows()['fy_to_previous_day']?->label());

    $c = $ctx($admin, ['month' => '2026-08']);
    check('past month -> last day of month', '2026-08-31', $c->asOn->format('Y-m-d'));
    check('past month not live', false, $c->isLive);
    check('past month: FY to previous day', '01-04-2026 to 30-08-2026', $c->windows()['fy_to_previous_day']?->label());
    check('past month: month to previous day', '01-08-2026 to 30-08-2026', $c->windows()['month_to_previous_day']?->label());
    check('past month: "today" window = 31-08', '31-08-2026', $c->windows()['today']->label());

    $c = $ctx($admin, ['month' => '2026-10']);
    check('current month -> today', '2026-10-06', $c->asOn->format('Y-m-d'));

    $c = $ctx($admin, ['month' => '2026-11']);
    check('future month ignored', null, $c->filters['month']);
    check('future month explained', 1, count($c->notices));

    $c = $ctx($admin, ['fy' => '1']);
    check('past FY -> FY end', '2026-03-31', $c->asOn->format('Y-m-d'));
    check('past FY label', 'FY 2025-26', $c->fy->label());

    $c = $ctx($admin, ['fy' => '3']);
    check('future FY not selectable', 'FY 2026-27', $c->fyRow['label']);
    check('month of other FY ignored', null, $ctx($admin, ['fy' => '1', 'month' => '2026-08'])->filters['month']);
    check('garbage month ignored', null, $ctx($admin, ['month' => "2026-08' OR 1=1"])->filters['month']);

    // --- Filters validated against data scope -------------------------------------------
    $c = $ctx($jana, ['branch' => '2', 'employee' => '3', 'customer' => '6']);
    check('JANA cannot pick another branch', null, $c->filters['branch_id']);
    check('JANA cannot pick another employee', null, $c->filters['employee_id']);
    check('JANA cannot pick another rep\'s customer', null, $c->filters['customer_id']);
    check('three notices explain why', 3, count($c->notices));
    check('JANA branch options = own branch only', [1], array_keys($c->branchOptions()));
    check('JANA employee options = herself only', [2], array_keys($c->employeeOptions(null)));

    $c = $ctx($jana, ['customer' => '1']);
    check('JANA can pick her own customer', 1, $c->filters['customer_id']);
    [$sql, $params] = $c->where(['branch' => 'v.branch_id', 'employee' => 'v.employee_id', 'customer' => 'v.customer_id']);
    check('where = scope AND filter', ['v.employee_id IN (?) AND v.customer_id = ?', [2, 1]], [$sql, $params]);

    $c = $ctx($admin, ['branch' => '2', 'product' => '3']);
    [$sql, $params] = $c->where(['branch' => 'v.branch_id', 'employee' => 'v.employee_id']);
    check('product filter on document-level rows matches nothing', ['1 = 0', []], [$sql, $params]);
    [$sql, $params] = $c->where(['branch' => 'v.branch_id', 'employee' => 'v.employee_id', 'product' => 'v.product_id']);
    check('product filter on line rows', ['1 = 1 AND v.branch_id = ? AND v.product_id = ?', [2, 3]], [$sql, $params]);
    check('admin sees all 5 reps', 5, count($c->employeeOptions(null)));
    check('admin branch 2 reps', [5, 4], array_keys($c->employeeOptions(2)));

    // --- Quick ADD ----------------------------------------------------------------------
    $janaAdd = new QuickAddService($jana, $today);
    $adminAdd = new QuickAddService($admin, $today);
    $coordAdd = new QuickAddService($coord, $today);

    check('JANA (view only) may add nothing', [], array_keys(QuickAddService::allowedTypes($jana)));
    check('coordinator may add targets, pending orders, samples and DC', ['target', 'pending_order', 'sample', 'dc'], array_keys(QuickAddService::allowedTypes($coord)));
    check('admin may add all 6 types', 6, count(QuickAddService::allowedTypes($admin)));
    [$st] = $coordAdd->handle('sale', []);
    check('server refuses coordinator sale (403)', 403, $st);

    // Give JANA collections.add to exercise her data scope.
    Database::query("INSERT INTO user_permissions (user_id, permission_id, effect) SELECT ?, id, 'grant' FROM permissions WHERE slug IN ('collections.add','sales.add')", [$jana['id']]);
    Gate::forget();
    [$st, $body] = $janaAdd->handle('collection', ['receipt_no' => 'T/1', 'receipt_date' => '2026-10-06', 'customer_id' => '6', 'amount' => '1000', 'payment_mode' => 'upi']);
    check('JANA cannot record collection for another rep\'s customer', 'You do not have access to this customer.', $body['errors']['customer_id'] ?? null);

    // FIFO allocation: customer 1 (JANA). Oldest open bill is INV/25-26/0901 (47,200).
    $before = Database::fetchAll('SELECT invoice_id, balance FROM v_invoice_balances WHERE customer_id = 1 AND balance > 0 ORDER BY invoice_date, invoice_id LIMIT 2');
    [$st, $body] = $janaAdd->handle('collection', ['receipt_no' => 'T/2', 'receipt_date' => '2026-10-06', 'customer_id' => '1', 'amount' => '50,000', 'payment_mode' => 'neft', 'auto_allocate' => '1']);
    check('collection saved', 200, $st);
    $after = array_column(Database::fetchAll('SELECT invoice_id, balance FROM v_invoice_balances WHERE invoice_id IN (?, ?)', [$before[0]['invoice_id'], $before[1]['invoice_id']]), 'balance', 'invoice_id');
    check('oldest bill fully cleared', '0.00', $after[$before[0]['invoice_id']]);
    $expectedSecond = number_format((float) $before[1]['balance'] - (50000 - (float) $before[0]['balance']), 2, '.', '');
    check('remainder applied to next bill', $expectedSecond, $after[$before[1]['invoice_id']]);
    check('employee defaults to customer\'s rep', 2, (int) Database::value("SELECT employee_id FROM collections WHERE receipt_no = 'T/2'"));
    check('electronic payment is cleared', 'cleared', Database::value("SELECT status FROM collections WHERE receipt_no = 'T/2'"));

    [$st, $body] = $janaAdd->handle('collection', ['receipt_no' => 'T/2', 'receipt_date' => '2026-10-06', 'customer_id' => '1', 'amount' => '10', 'payment_mode' => 'cheque']);
    check('duplicate receipt number rejected', true, str_contains($body['errors']['receipt_no'] ?? '', 'already exists'));

    // Sale: GST from product, exact paise, due date from credit days.
    [$st, $body] = $adminAdd->handle('sale', ['invoice_no' => 'T/INV/1', 'invoice_date' => '2026-10-06', 'customer_id' => '2', 'product_id' => '3', 'quantity' => '7', 'taxable_amount' => '1000.01']);
    check('sale saved', 200, $st);
    $inv = Database::fetch("SELECT taxable_amount, tax_amount, total_amount, due_date, employee_id, branch_id, financial_year_id FROM sales_invoices WHERE invoice_no = 'T/INV/1'");
    check('GST 18% to the paisa', ['1000.01', '180.00', '1180.01'], [$inv['taxable_amount'], $inv['tax_amount'], $inv['total_amount']]);
    check('due date = date + 45 credit days', '2026-11-20', $inv['due_date']);
    check('branch/employee snapshot from customer', [1, 2], [(int) $inv['branch_id'], (int) $inv['employee_id']]);
    check('FY derived from date', 2, (int) $inv['financial_year_id']);
    check('sale has its line item', 1, (int) Database::value("SELECT COUNT(*) FROM sales_invoice_items i JOIN sales_invoices s ON s.id = i.invoice_id WHERE s.invoice_no = 'T/INV/1'"));
    check('audit written', 1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'sale.created' AND JSON_EXTRACT(new_data, '$.invoice_no') = 'T/INV/1'"));

    [$st, $body] = $adminAdd->handle('sale', ['invoice_no' => 'T/INV/2', 'invoice_date' => '2026-10-07', 'customer_id' => '2', 'product_id' => '3', 'quantity' => '1', 'taxable_amount' => '10']);
    check('future-dated sale rejected', 'Invoice date cannot be in the future.', $body['errors']['invoice_date'] ?? null);
    [$st, $body] = $adminAdd->handle('sale', ['invoice_no' => '<script>', 'invoice_date' => '2026-10-06', 'customer_id' => '2', 'product_id' => '3', 'quantity' => '0', 'taxable_amount' => '-5']);
    check('bad input: 422 with field errors', [422, true, true, true], [$st, isset($body['errors']['invoice_no']), isset($body['errors']['quantity']), isset($body['errors']['taxable_amount'])]);

    Database::query('UPDATE financial_years SET is_locked = 1 WHERE id = 1');
    [$st, $body] = $adminAdd->handle('dc', ['dc_no' => 'T/DC', 'dc_date' => '2026-03-15', 'customer_id' => '2', 'product_id' => '1', 'quantity' => '1']);
    check('locked FY rejects entries', 'FY 2025-26 is locked; entries are not allowed.', $body['errors']['dc_date'] ?? null);

    // Targets: whole-year split is exact; second save replaces.
    [$st, $body] = $adminAdd->handle('target', ['employee_id' => '3', 'fy_id' => '2', 'month' => 'all', 'sales_target' => '1000000.05', 'collection_target' => '900000']);
    check('yearly target saved', 200, $st);
    check('12 monthly rows sum exactly to the yearly amount', '1000000.05', Database::value('SELECT SUM(sales_target) FROM sales_targets WHERE employee_id = 3 AND financial_year_id = 2'));
    check('remainder sits in March', '83333.42', Database::value("SELECT sales_target FROM sales_targets WHERE employee_id = 3 AND target_month = '2027-03-01'"));
    [$st, $body] = $adminAdd->handle('target', ['employee_id' => '3', 'fy_id' => '2', 'month' => '2026-10', 'sales_target' => '95000']);
    check('single month replaced', '95000.00', Database::value("SELECT sales_target FROM sales_targets WHERE employee_id = 3 AND target_month = '2026-10-01'"));
    check('still 12 rows (upsert, not duplicate)', 12, (int) Database::value('SELECT COUNT(*) FROM sales_targets WHERE employee_id = 3 AND financial_year_id = 2'));
    [$st, $body] = $adminAdd->handle('target', ['employee_id' => '1', 'fy_id' => '2', 'month' => '2026-10', 'sales_target' => '1']);
    check('targets only for sales reps', 'Choose a sales employee.', $body['errors']['employee_id'] ?? null);

    // Pending order: fully supplied -> closed, not pending.
    [$st, $body] = $adminAdd->handle('pending_order', ['order_no' => 'T/SO/1', 'order_date' => '2026-10-06', 'customer_id' => '3', 'product_id' => '2',
        'order_qty' => '10', 'order_value' => '11500', 'supplied_qty' => '4', 'supplied_value' => '4600', 'expected_delivery_date' => '2026-10-20']);
    check('order saved', 200, $st);
    check('pending value generated by DB', '6900.00', Database::value("SELECT i.pending_value FROM pending_order_items i JOIN pending_orders o ON o.id = i.order_id WHERE o.order_no = 'T/SO/1'"));
    check('partially supplied -> partial', 'partial', Database::value("SELECT status FROM pending_orders WHERE order_no = 'T/SO/1'"));
    [$st, $body] = $adminAdd->handle('pending_order', ['order_no' => 'T/SO/2', 'order_date' => '2026-10-06', 'customer_id' => '3', 'product_id' => '2',
        'order_qty' => '10', 'order_value' => '100', 'supplied_value' => '200']);
    check('supplied > ordered rejected', 'Supplied value cannot exceed the order value.', $body['errors']['supplied_value'] ?? null);

    [$st] = $adminAdd->handle('sample', ['document_no' => 'T/SMP', 'document_date' => '2026-10-06', 'customer_id' => '4', 'product_id' => '5', 'quantity' => '2', 'sample_value' => '320', 'supply_status' => 'supplied']);
    check('sample saved', 200, $st);
    [$st] = $adminAdd->handle('dc', ['dc_no' => 'T/DC/2', 'dc_date' => '2026-10-06', 'customer_id' => '4', 'product_id' => '5', 'quantity' => '2', 'dc_value' => '320']);
    check('DC saved', 200, $st);

    check('integrity rules still pass after entries', 0, count(array_filter((new App\Services\DataIntegrity())->run(), static fn ($r) => $r['count'] > 0 && $r['severity'] === 'error')));
} finally {
    $pdo->rollBack();
}

echo PHP_EOL . "{$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
