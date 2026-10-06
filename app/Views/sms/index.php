<?php
/** @var array<string, mixed> $filters */
/** @var array<string, mixed> $stats */
/** @var list<array<string, mixed>> $messages */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var bool $testMode */
use App\Modules\Sms\SmsController;

$q = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== null && $v !== ''));
$badge = ['delivered' => 'badge-ok', 'sent' => 'badge-ok', 'failed' => 'badge-fail', 'queued' => 'badge-info', 'pending' => 'badge-warn', 'cancelled' => 'badge-muted'];
$smsTab = 'history';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SMS</p>
        <h1>SMS</h1>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="import-stats">
    <div class="card stat stat-ok"><span>Sent today</span><strong><?= e((int) $stats['sent_today']) ?></strong></div>
    <a class="card stat stat-fail" href="?status=failed&amp;from=<?= e(date('Y-m-d')) ?>"><span>Failed today</span><strong><?= e((int) $stats['failed_today']) ?></strong></a>
    <a class="card stat" href="?status=queued"><span>Waiting / scheduled</span><strong><?= e((int) $stats['waiting']) ?></strong></a>
    <div class="card stat"><span>This month (SMS parts)</span><strong><?= e((int) $stats['sent_month']) ?> <small class="muted">(<?= e((int) $stats['segments_month']) ?>)</small></strong></div>
</section>

<form method="get" action="<?= e(url('sms')) ?>" class="filters filters-5 card">
    <label class="field"><span>Search</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Mobile, name, text"></label>
    <label class="field"><span>Status</span><select name="status"><option value="">Any</option>
        <?php foreach (SmsController::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>From</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="field"><span>To</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <div class="filter-actions">
        <?php if ($filters['campaign']): ?><input type="hidden" name="campaign" value="<?= e($filters['campaign']) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary">Filter</button><a class="btn" href="<?= e(url('sms')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> message<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($messages === []): ?>
        <p class="empty">No messages yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Created</th><th>To</th><th>Message</th><th class="right">Parts</th><th>Campaign</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($messages as $m): ?>
            <tr>
                <td class="small nowrap"><?= e(date('d-m-Y H:i', strtotime($m['created_at']))) ?><div class="muted"><?= e($m['user_name'] ?? '') ?></div></td>
                <td class="small"><a href="<?= e(url("sms/messages/{$m['id']}")) ?>"><?= e($m['recipient_mobile']) ?></a><div class="muted"><?= e($m['recipient_name'] ?? '') ?></div></td>
                <td class="small"><?= e(mb_strimwidth($m['message'], 0, 90, '…')) ?></td>
                <td class="right num"><?= e($m['segments']) ?><?= $m['encoding'] === 'unicode' ? ' <span class="muted small">U</span>' : '' ?></td>
                <td class="small"><?= $m['campaign_id'] ? '<a href="' . e(url("sms/campaigns/{$m['campaign_id']}")) . '">' . e($m['campaign_name']) . '</a>' : '<span class="muted">single</span>' ?></td>
                <td class="badge-stack"><span class="badge <?= e($badge[$m['status']] ?? 'badge-muted') ?>"><?= e(SmsController::STATUSES[$m['status']]) ?></span>
                    <?= $m['is_test'] ? '<span class="badge badge-muted">test</span>' : '' ?>
                    <?= $m['scheduled_at'] && $m['status'] === 'queued' ? '<span class="muted small">' . e(date('d-m H:i', strtotime($m['scheduled_at']))) . '</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($q(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($q(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
