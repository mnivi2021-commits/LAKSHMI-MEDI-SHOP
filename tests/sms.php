<?php

declare(strict_types=1);

/*
 * SMS (Phase 19):  php tests/sms.php
 * Length / encoding rules, placeholders, recipients (scope, opt-out, de-dup),
 * queue -> dispatch through the test gateway, scheduling, campaign counts,
 * no double sending, masked logs. Seeded DB; rolled back.
 */

use App\Core\Database;
use App\Core\Money;
use App\Modules\Sms\SmsService;
use App\Modules\Sms\SmsText;
use App\Services\Sms\MockGateway;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();
try {
    // ------------------------------------------------------------- length
    check('160 GSM chars = 1 SMS', ['gsm7', 160, 1], array_values(array_slice(SmsText::measure(str_repeat('a', 160)), 0, 3)));
    check('161 GSM chars = 2 SMS (153 each)', 2, SmsText::measure(str_repeat('a', 161))['segments']);
    check('307 GSM chars = 3 SMS', 3, SmsText::measure(str_repeat('a', 307))['segments']);
    check('{ } [ ] count double', 8, SmsText::measure('{[]}')['length']);
    check('€ makes 2 GSM units', 2, SmsText::measure('€')['length']);
    check('₹ forces Unicode', 'unicode', SmsText::measure('Pay ₹500')['encoding']);
    check('70 Unicode chars = 1 SMS', 1, SmsText::measure(str_repeat('த', 70))['segments']);
    check('71 Unicode chars = 2 SMS (67 each)', 2, SmsText::measure(str_repeat('த', 71))['segments']);

    // -------------------------------------------------------- placeholders
    check('unknown placeholder detected', ['price'], SmsText::unknownPlaceholders('Hi {name}, {price}'));
    check('render fills values', ['Hi Ravi, from ACME', []], SmsText::render('Hi {name}, from {company}', ['name' => 'Ravi', 'company' => 'ACME']));
    check('render reports empty placeholder', ['Rs {amount}', ['amount']], SmsText::render('Rs {amount}', ['amount' => null]));
    check('render never evaluates code', ['x <?php echo 1; ?> {', []], SmsText::render('x {name} {', ['name' => '<?php echo 1; ?>']));
    check('newlines in values flattened', ['A B', []], SmsText::render('{name}', ['name' => "A\r\nB"]));
    foreach (['+91 98765 43210' => '9876543210', '09876543210' => '9876543210', '919876543210' => '9876543210', '5876543210' => null, '98765' => null] as $in => $want) {
        check("mobile {$in}", $want, SmsText::mobile((string) $in));
    }

    // ---------------------------------------------------------- recipients
    $admin = seeded_user('admin');
    $jana = seeded_user('jana');
    Database::query("UPDATE customers SET sms_opt_out = 1 WHERE customer_code = 'CUS-00002'");
    Database::query("UPDATE customers SET mobile = '9100000001' WHERE customer_code = 'CUS-00003'");   // duplicate of CUS-00001
    Database::query("UPDATE customers SET mobile = '123' WHERE customer_code = 'CUS-00004'");
    $r = SmsService::recipients('customers', [], $admin, $today);
    check('admin: 12 active customers minus 3 excluded', 9, count($r['recipients']));
    check('exclusions reported', ['opted_out' => 1, 'no_mobile' => 1, 'duplicate' => 1], $r['excluded']);
    check('opted-out customer never listed', false, in_array(2, array_column($r['recipients'], 'customer_id'), true));
    $first = $r['recipients'][0];
    check('vars include company and date', [true, '06-10-2026'], [$first['vars']['company'] !== '', $first['vars']['date']]);

    $rj = SmsService::recipients('customers', [], $jana, $today);
    check('JANA: only her customers', true, array_diff(array_column($rj['recipients'], 'customer_id'), [1, 2, 5]) === []);

    $ro = SmsService::recipients('customers', ['overdue_only' => true], $admin, $today);
    $c1 = array_values(array_filter($ro['recipients'], static fn ($x) => $x['customer_id'] === 1))[0] ?? null;
    $expected = Money::fromDb(Database::value('SELECT SUM(balance) FROM v_invoice_balances WHERE customer_id = 1 AND balance > 0 AND due_date < ?', ['2026-10-06']));
    check('overdue_only: amount = customer overdue balance exactly', $expected, $c1 !== null ? Money::parse($c1['vars']['amount']) : null);
    foreach ([33016450 => '3,30,164.50', 100 => '1.00', 123456789 => '12,34,567.89', 5 => '0.05'] as $p => $want) {
        check("amount format {$p}", $want, SmsService::amount($p));
    }
    check('overdue_only: subset of all customers', true, count($ro['recipients']) <= count($r['recipients']));

    $rb = SmsService::recipients('customers', ['branch_id' => 2], $admin, $today);
    check('branch filter', 4, count($rb['recipients']));
    check('leads: open leads with mobile', true, count(SmsService::recipients('leads', [], $admin, $today)['recipients']) > 0);
    check('employees target', 9, count(SmsService::recipients('employees', [], $admin, $today)['recipients']));

    // ------------------------------------------------- queue and dispatch
    $gw = new MockGateway();
    Database::query("INSERT INTO sms_campaigns (name, message_body, target_type, status, created_by) VALUES ('T', 'x', 'customers', 'processing', 1)");
    $cid = (int) $pdo->lastInsertId();
    $ids = SmsService::queue([
        ['mobile' => '9876543210', 'name' => 'A', 'customer_id' => 1, 'lead_id' => null, 'employee_id' => null, 'message' => 'Hello A'],
        ['mobile' => '9876500000', 'name' => 'B', 'customer_id' => null, 'lead_id' => null, 'employee_id' => null, 'message' => 'Hello B ₹'],
    ], null, $cid, null, 1, $gw);
    $q = static fn (int $id): array => array_values(Database::fetch('SELECT encoding, is_test, status FROM sms_messages WHERE id = ?', [$id]));
    check('queued with encoding and test flag', [['gsm7', 1, 'queued'], ['unicode', 1, 'queued']], array_map($q, $ids));
    $res = SmsService::dispatch(10, $ids, $gw);
    check('dispatch: 1 delivered, 1 failed (0000)', ['sent' => 1, 'failed' => 1], $res);
    check('failed message keeps the reason', 'Number not reachable (test gateway)', Database::value('SELECT error_message FROM sms_messages WHERE id = ?', [$ids[1]]));
    check('delivered has provider id + times', true, (bool) Database::value("SELECT provider_message_id LIKE 'MOCK-%' AND sent_at IS NOT NULL AND delivered_at IS NOT NULL FROM sms_messages WHERE id = ?", [$ids[0]]));
    check('second dispatch sends nothing again', ['sent' => 0, 'failed' => 0], SmsService::dispatch(10, $ids, $gw));
    check('each message attempted once', [1, 1], array_map(static fn ($id) => (int) Database::value('SELECT attempts FROM sms_messages WHERE id = ?', [$id]), $ids));
    $logs = implode(' ', array_column(Database::fetchAll('SELECT payload FROM sms_logs WHERE sms_message_id IN (?, ?)', $ids), 'payload'));
    check('logs: request + response/error per message', 4, (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE sms_message_id IN (?, ?)', $ids));
    check('logs never contain the full number', false, str_contains($logs, '9876543210'));
    $camp = Database::fetch('SELECT * FROM sms_campaigns WHERE id = ?', [$cid]);
    check('campaign counts recomputed and completed', [2, 1, 1, 1, 'completed'],
        [(int) $camp['total_recipients'], (int) $camp['sent_count'], (int) $camp['delivered_count'], (int) $camp['failed_count'], $camp['status']]);

    // ----------------------------------------------------------- scheduling
    Database::query("INSERT INTO sms_campaigns (name, message_body, target_type, status, scheduled_at, created_by) VALUES ('S', 'x', 'customers', 'scheduled', '2026-10-07 10:00:00', 1)");
    $sid = (int) $pdo->lastInsertId();
    $later = SmsService::queue([['mobile' => '9876511111', 'name' => 'C', 'customer_id' => null, 'lead_id' => null, 'employee_id' => null, 'message' => 'Later'],
                                ['mobile' => '9876522222', 'name' => 'D', 'customer_id' => null, 'lead_id' => null, 'employee_id' => null, 'message' => 'Later']],
        null, $sid, '2026-10-07 10:00:00', 1, $gw);
    check('not due yet -> not sent', ['sent' => 0, 'failed' => 0], SmsService::dispatch(10, $later, $gw, new DateTimeImmutable('2026-10-07 09:59:00')));
    check('due -> sent', ['sent' => 2, 'failed' => 0], SmsService::dispatch(10, $later, $gw, new DateTimeImmutable('2026-10-07 10:00:00')));

    // --------------------------------------------------------------- cancel
    Database::query("INSERT INTO sms_campaigns (name, message_body, target_type, status, scheduled_at, created_by) VALUES ('X', 'x', 'customers', 'scheduled', '2026-12-01 10:00:00', 1)");
    $xid = (int) $pdo->lastInsertId();
    SmsService::queue([['mobile' => '9876533333', 'name' => 'E', 'customer_id' => null, 'lead_id' => null, 'employee_id' => null, 'message' => 'Never']], null, $xid, '2026-12-01 10:00:00', 1, $gw);
    check('cancel stops queued messages', 1, SmsService::cancelCampaign($xid, 1));
    check('cancelled campaign stays cancelled', 'cancelled', Database::value('SELECT status FROM sms_campaigns WHERE id = ?', [$xid]));
    check('cancelled message is never sent', ['sent' => 0, 'failed' => 0], SmsService::dispatch(10, null, $gw, new DateTimeImmutable('2026-12-02')));
    check('mask', '98xxxxx210', SmsService::mask('9876543210'));
} finally {
    $pdo->rollBack();
}

exit(test_summary());
