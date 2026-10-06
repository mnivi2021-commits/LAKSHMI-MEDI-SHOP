<?php

declare(strict_types=1);

/*
 * Mail (Phase 18):  php tests/mail.php
 * Rule classifier, sender matching, de-duplication, corrections, assignment,
 * lead creation, data scope, and that every dashboard Email card count equals
 * the Mail list it opens. Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Mail\Classifier;
use App\Modules\Mail\MailQuery;
use App\Modules\Mail\MailService;
use App\Services\Mail\IncomingMail;
use App\Services\Mail\Providers;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$today = new DateTimeImmutable('2026-10-06');
$catId = static fn (string $code): int => (int) Database::value('SELECT id FROM email_categories WHERE code = ?', [$code]);
$mail = static fn (string $id, string $from, string $subject, string $body = '', string $at = '2026-10-06 11:00:00') =>
    new IncomingMail($id, $from, null, $subject, $body, $at);

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    // ------------------------------------------------------------ classifier
    $c = new Classifier();
    $r = $c->classify('Purchase Order PO-4471', 'Please dispatch at the earliest.');
    check('purchase order -> order', $catId('order'), $r['category_id']);
    check('strong evidence -> high confidence', true, $r['confidence'] >= 90);
    $r = $c->classify('Payment advice', 'UTR 1234 for NEFT payment of invoices');
    check('payment advice -> payment_advice', $catId('payment_advice'), $r['category_id']);
    $r = $c->classify('Packaging news - October', 'This week in the industry');
    check('no keyword -> fallback Other', [$catId('other'), null, 'none'], [$r['category_id'], $r['confidence'], $r['method']]);
    $r = $c->classify('Re: rate', 'see attached');
    check('weak single keyword -> low confidence (needs review)', true, $r['confidence'] !== null && $r['confidence'] < Classifier::REVIEW_BELOW);
    $r = $c->classify('Order and payment', '');
    check('two categories tie-ish -> confidence below 60', true, $r['confidence'] < 60);
    $r = $c->classify('Recorder repair', 'border reorder');
    check('whole words only (recorder/border/reorder are not "order")', $catId('other'), $r['category_id']);
    $r = $c->classify('PURCHASE ORDER', '');
    check('case-insensitive', $catId('order'), $r['category_id']);

    // -------------------------------------------------------------- receive
    $id = MailService::receive(1, $mail('t-1', 'Accounts@Balaji.example.com', 'Payment advice - March bills', 'NEFT UTR 99'));
    $m = Database::fetch('SELECT * FROM email_messages WHERE id = ?', [$id]);
    check('exact customer email matched (case-insensitive)', [1, 1, 2], [(int) $m['customer_id'], (int) $m['branch_id'], (int) $m['employee_id']]);
    check('classified and auto category kept', [$catId('payment_advice'), $catId('payment_advice'), 'rule'], [(int) $m['category_id'], (int) $m['auto_category_id'], $m['classification_method']]);
    check('activity: received + classified', 2, (int) Database::value('SELECT COUNT(*) FROM email_activity WHERE email_id = ?', [$id]));
    check('same provider id is not stored twice', null, MailService::receive(1, $mail('t-1', 'x@y.com', 'again')));
    $id2 = MailService::receive(1, $mail('t-2', 'ravi@kongu.example.com', 'Enquiry for tape'));
    check('company domain matched (one customer uses it)', 6, (int) Database::value('SELECT customer_id FROM email_messages WHERE id = ?', [$id2]));
    $id3 = MailService::receive(1, $mail('t-3', 'someone@gmail.com', 'Enquiry'));
    $m3 = Database::fetch('SELECT * FROM email_messages WHERE id = ?', [$id3]);
    check('public domain never matched; mailbox branch used', [null, 1, null], [$m3['customer_id'], (int) $m3['branch_id'], $m3['employee_id']]);
    $id4 = MailService::receive(1, $mail('t-4', 'arun@chola.example.com', 'Follow up'));
    check('lead matched by email', true, Database::value('SELECT lead_id FROM email_messages WHERE id = ?', [$id4]) !== null);

    // ------------------------------------------------ correction / reclassify
    $admin = seeded_user('admin');
    $m3 = Database::fetch('SELECT * FROM email_messages WHERE id = ?', [$id3]);
    MailService::recategorise($m3, $catId('new_lead'), (int) $admin['id']);
    $m3 = Database::fetch('SELECT * FROM email_messages WHERE id = ?', [$id3]);
    check('manual correction', [$catId('new_lead'), $catId('new_enquiry'), 'manual'], [(int) $m3['category_id'], (int) $m3['auto_category_id'], $m3['classification_method']]);
    MailService::reclassify((int) $admin['id']);
    check('reclassify never overrides a manual correction', $catId('new_lead'), (int) Database::value('SELECT category_id FROM email_messages WHERE id = ?', [$id3]));

    // ---------------------------------------------------------------- lead
    $leadId = MailService::createLead($m3, 1, null, (int) $admin['id']);
    $lead = Database::fetch('SELECT * FROM leads WHERE id = ?', [$leadId]);
    check('lead from email: source Email, linked both ways', ['someone@gmail.com', 'Email', $id3],
        [$lead['email'], Database::value('SELECT name FROM lead_sources WHERE id = ?', [$lead['source_id']]), (int) $lead['email_message_id']]);
    $m3 = Database::fetch('SELECT status, lead_id FROM email_messages WHERE id = ?', [$id3]);
    check('email now in progress and linked', ['in_progress', $leadId], [$m3['status'], (int) $m3['lead_id']]);

    // -------------------------------------------------------- scope / assign
    $jana = seeded_user('jana');
    $kongu = Database::fetch('SELECT * FROM email_messages WHERE id = ?', [$id2]);   // Coimbatore customer, PRAKASH
    check('JANA cannot see a Coimbatore email', null, MailQuery::visible($jana, $id2));
    check('JANA sees her customer\'s email', true, MailQuery::visible($jana, $id) !== null);
    MailService::assign($kongu, $jana, (int) $admin['id'], 'Please call back');
    check('assigned email becomes visible to JANA', true, MailQuery::visible($jana, $id2) !== null);
    check('assignment moved owner to JANA, status open', [2, 'open'], [(int) Database::value('SELECT employee_id FROM email_messages WHERE id = ?', [$id2]), Database::value('SELECT status FROM email_messages WHERE id = ?', [$id2])]);
    check('only one current assignment', 1, (int) Database::value('SELECT COUNT(*) FROM email_assignments WHERE email_id = ? AND is_current = 1', [$id2]));

    // ------------------------------------- dashboard cards == mail list counts
    foreach (['admin' => $admin, 'jana' => $jana] as $who => $u) {
        $ctx = DashboardContext::fromRequest($u, [], $today);
        $panel = DashboardController::mailPanel($ctx, $u);
        foreach ($panel['cards'] as $card) {
            parse_str($card['href_month'], $q);
            $listCount = MailQuery::count($u, ['category' => $q['category'], 'from' => $q['from'], 'to' => $q['to'], 'branch' => $q['branch'] ?? null, 'employee' => $q['employee'] ?? null]);
            check("{$who}: {$card['code']} card = list it opens", $card['month'], $listCount);
            parse_str($card['href_open'], $q);
            check("{$who}: {$card['code']} open = list", $card['open'], MailQuery::count($u, ['category' => $q['category'], 'to' => $q['to'], 'open' => true]));
        }
        $all = (int) Database::value("SELECT COUNT(*) FROM email_messages WHERE received_at >= '2026-10-01' AND received_at < '2026-10-07'");
        if ($who === 'admin') {
            check('admin: cards add up to every email this month', $all, array_sum(array_column($panel['cards'], 'month')));
        } else {
            check('jana: sees fewer emails than admin', true, array_sum(array_column($panel['cards'], 'month')) < $all);
        }
    }
    $ctx = DashboardContext::fromRequest($admin, ['branch' => '2'], $today);
    $panel = DashboardController::mailPanel($ctx, $admin);
    check('branch filter passes through to the card link', true, str_contains($panel['cards'][0]['href_month'], 'branch=2'));
    check('branch filter: counts only Coimbatore emails', (int) Database::value("SELECT COUNT(*) FROM email_messages WHERE branch_id = 2 AND received_at >= '2026-10-01' AND received_at < '2026-10-07'"),
        array_sum(array_column($panel['cards'], 'month')));

    // ------------------------------------------------------------- providers
    $imap = Providers::for('imap');
    check('IMAP explains what is missing (extension or .env)', true, is_string($imap->unavailableReason()));
    check('Gmail API honestly not configured', true, str_contains((string) Providers::for('gmail')->unavailableReason(), 'not set up'));
} finally {
    $pdo->rollBack();
}

exit(test_summary());
