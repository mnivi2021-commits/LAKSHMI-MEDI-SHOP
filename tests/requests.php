<?php

declare(strict_types=1);

/*
 * Requests (Screen B), database side:  php tests/requests.php
 * Seeded DB; rolled back.
 */

use App\Core\Database;
use App\Core\Gate;
use App\Core\NumberSequence;
use App\Modules\Requests\RequestForm;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $admin = seeded_user('admin');
    $jana = seeded_user('jana');
    $coord = seeded_user('coordinator');

    // ---------------------------------------------------------- permissions
    check('rep can raise orders', true, Gate::allows('pending_orders.add', $jana));
    check('rep can raise DC', true, Gate::allows('dc.add', $jana));
    check('rep can raise samples', true, Gate::allows('samples.add', $jana));
    check('rep cannot approve samples', false, Gate::allows('samples.approve', $jana));
    check('rep cannot add customers', false, Gate::allows('customers.add', $jana));
    check('coordinator can raise orders', true, Gate::allows('pending_orders.add', $coord));
    check('coordinator cannot approve samples', false, Gate::allows('samples.approve', $coord));
    foreach (['sales_manager', 'branch_manager', 'admin_head'] as $r) {
        check("{$r} approves samples", 1, (int) Database::value(
            "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = ? AND p.slug = 'samples.approve'", [$r]));
    }

    // ---------------------------------------------------------- numbering
    check('enquiry numbers', 1, preg_match('/^ENQ-\d{5}$/', NumberSequence::next('enquiry')));
    check('order numbers', 1, preg_match('/^ORD-\d{5}$/', NumberSequence::next('order')));
    check('DC numbers', 1, preg_match('/^DCR-\d{5}$/', NumberSequence::next('dc')));
    check('sample numbers', 1, preg_match('/^SMR-\d{5}$/', NumberSequence::next('sample')));

    // ---------------------------------------------------------- only approved samples count
    $before = (int) Database::value('SELECT COUNT(*) FROM v_pending_sample_lines');
    Database::query("INSERT INTO samples (financial_year_id, document_no, document_date, customer_id, branch_id, employee_id, supply_status, sample_type,
                                          approval_status, pending_status, created_by, updated_by)
                     VALUES (2, 'T-SMP-1', '2026-10-06', 1, 1, 2, 'not_supplied', 'returnable', 'requested', 'pending', 1, 1)");
    $sid = (int) $pdo->lastInsertId();
    Database::query('INSERT INTO sample_items (sample_id, product_id, quantity, sample_value) VALUES (?, 1, 2, 500)', [$sid]);
    check('requested sample not pending', $before, (int) Database::value('SELECT COUNT(*) FROM v_pending_sample_lines'));
    Database::query("UPDATE samples SET approval_status = 'approved' WHERE id = ?", [$sid]);
    check('approved sample pending', $before + 1, (int) Database::value('SELECT COUNT(*) FROM v_pending_sample_lines'));
    Database::query("UPDATE samples SET approval_status = 'rejected' WHERE id = ?", [$sid]);
    check('rejected sample not pending', $before, (int) Database::value('SELECT COUNT(*) FROM v_pending_sample_lines'));
    check('existing samples default to approved', 0, (int) Database::value("SELECT COUNT(*) FROM samples WHERE id <> ? AND approval_status <> 'approved'", [$sid]));

    // ---------------------------------------------------------- form rules
    $errors = [];
    $f = new RequestForm($jana, ['lines' => [
        ['product_id' => '1', 'qty' => '3', 'price' => '12.50'],
        ['description' => 'Custom tape', 'qty' => '2', 'price' => '7'],
        ['qty' => '', 'price' => ''],
    ]], $errors);
    $lines = $f->lines(true, 'Price');
    check('blank line skipped', 2, count($lines));
    check('qty x price in paise', 3750, $lines[0]['amount']);
    check('free text kept for leads', 'Custom tape', $lines[1]['description']);
    check('no errors', [], $errors);

    $errors = [];
    $f = new RequestForm($jana, ['lines' => [['description' => 'Custom tape', 'qty' => '2', 'price' => '7']]], $errors);
    $f->lines(false, 'Rate');
    check('orders need a master product', true, isset($errors['lines.0.product']));

    $errors = [];
    $f = new RequestForm($jana, ['lines' => [['product_id' => '1', 'qty' => '0', 'price' => '7']]], $errors);
    $f->lines(false, 'Rate');
    check('zero quantity refused', true, isset($errors['lines.0.qty']));

    $errors = [];
    $f = new RequestForm($jana, ['lines' => [['product_id' => '1', 'qty' => '4', 'price' => '900']]], $errors);
    check('sample value is the line total', 90000, $f->lines(false, 'Value', true)[0]['amount']);

    $errors = [];
    $f = new RequestForm($jana, ['customer_id' => '6'], $errors);
    $f->customer(true);
    check("another rep's customer refused", true, isset($errors['customer_id']));

    $errors = [];
    $f = new RequestForm($jana, ['customer_id' => '1'], $errors);
    $c = $f->customer(true);
    check('own customer accepted', 1, $c['id']);

    $errors = [];
    $f = new RequestForm($jana, ['new_name' => 'Rep New', 'new_mobile' => '9876500000', 'branch_id' => '1'], $errors);
    $c = $f->customer(false);
    check('lead: new customer kept on the lead only', null, $c['id']);
    check('lead: rep owns it', 2, $c['employee_id']);
    $f->customer(true);
    check('order: rep must ask for customer master update', true, str_contains($errors['customer_id'] ?? '', 'not in the customer master'));

    $errors = [];
    $f = new RequestForm($admin, ['new_name' => 'Admin New Co', 'new_mobile' => '+91 98765 00001', 'branch_id' => '1', 'employee_id' => '3'], $errors);
    $c = $f->customer(true);
    check('order: new customer added to master', 'Admin New Co', Database::value('SELECT name FROM customers WHERE id = ?', [$c['id']]));
    check('mobile normalised', '9876500001', Database::value('SELECT mobile FROM customers WHERE id = ?', [$c['id']]));

    $errors = [];
    $f = new RequestForm($admin, ['new_name' => 'Bad', 'new_mobile' => '12345', 'branch_id' => '1'], $errors);
    $f->customer(false);
    check('bad mobile refused', true, isset($errors['new_mobile']));
} finally {
    $pdo->rollBack();
}

exit(test_summary());
