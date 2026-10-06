<?php
/** @var list<array<string, mixed>> $accounts */
/** @var list<array<string, mixed>> $categories */
/** @var list<array<string, mixed>> $branches */
use App\Core\Csrf;
use App\Services\Mail\Providers;

ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('mail')) ?>">Mail</a></p>
        <h1>Mailboxes &amp; sorting rules</h1>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<section class="card table-card">
    <h2>Mailboxes</h2>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Mailbox</th><th>Read by</th><th>Branch</th><th class="right">Emails</th><th>Last fetched</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): $why = Providers::for($a['provider'])->unavailableReason(); ?>
            <tr>
                <td><strong><?= e($a['email_address']) ?></strong><div class="muted small"><?= e($a['display_name'] ?? '') ?></div></td>
                <td class="small"><?= e(Providers::LABELS[$a['provider']] ?? $a['provider']) ?><?= $why && $a['provider'] !== 'manual' ? '<div class="neg">' . e($why) . '</div>' : '' ?></td>
                <td class="small"><?= e($a['branch_code'] ?? '—') ?></td>
                <td class="right num"><?= e($a['emails']) ?></td>
                <td class="small"><?= $a['last_synced_at'] ? e(date('d-m-Y H:i', strtotime($a['last_synced_at']))) : '—' ?><?= $a['last_sync_error'] ? '<div class="neg">' . e($a['last_sync_error']) . '</div>' : '' ?></td>
                <td class="right">
                    <?php if ($a['provider'] !== 'manual'): ?>
                        <form method="post" action="<?= e(url("mail/accounts/{$a['id']}/sync")) ?>"><?= Csrf::field() ?><button type="submit" class="btn btn-sm">Fetch now</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <form method="post" action="<?= e(url('mail/accounts')) ?>" class="filters filters-5 padded">
        <?= Csrf::field() ?>
        <label class="field"><span>Mailbox address</span><input type="email" name="email_address" maxlength="150" required></label>
        <label class="field"><span>Name</span><input type="text" name="display_name" maxlength="100"></label>
        <label class="field"><span>Read by</span><select name="provider"><?php foreach (Providers::LABELS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Branch</span><select name="branch_id"><option value="">Any</option><?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"><?= e($b['branch_code']) ?></option><?php endforeach; ?></select></label>
        <div class="filter-actions"><button type="submit" class="btn btn-primary">Add mailbox</button></div>
    </form>
    <p class="muted small padded">IMAP needs MAIL_IMAP_HOST, MAIL_IMAP_USERNAME and MAIL_IMAP_PASSWORD (an app password) in the server's .env file and PHP's imap extension. Passwords are never stored in the database. Automatic fetching: schedule <code>php cli/mail-sync.php</code> every 5 minutes (Windows Task Scheduler).</p>
</section>

<section class="card table-card">
    <h2>Sorting rules</h2>
    <p class="muted small padded">One keyword or phrase per line, optionally with a weight from 1 to 10 (e.g. <code>purchase order : 4</code>). Words in the subject count double. An email goes to the category with the highest score; with no match it goes to the fallback category. Emails corrected by hand are never changed by the rules.</p>
    <div class="rules-grid padded">
        <?php foreach ($categories as $c): $kw = json_decode((string) $c['keywords'], true) ?: []; ?>
            <form method="post" action="<?= e(url("mail/categories/{$c['id']}")) ?>" class="rule-card">
                <?= Csrf::field() ?>
                <h3><span class="badge mail-badge mail-<?= e($c['color']) ?>"><?= e($c['name']) ?></span><?= $c['is_fallback'] ? ' <span class="muted small">fallback</span>' : '' ?></h3>
                <textarea name="keywords" rows="7" aria-label="Keywords for <?= e($c['name']) ?>"><?= e(implode("\n", array_map(static fn ($k) => $k['term'] . ' : ' . $k['weight'], $kw))) ?></textarea>
                <button type="submit" class="btn btn-sm">Save</button>
            </form>
        <?php endforeach; ?>
    </div>
    <form method="post" action="<?= e(url('mail/reclassify')) ?>" class="padded" data-confirm="Re-check every email not corrected by hand with the current rules?">
        <?= Csrf::field() ?><button type="submit" class="btn">Re-check emails with current rules</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
