<?php
/** @var array<string, mixed> $filters */
/** @var array<string, int> $counts */
/** @var list<array<string, mixed>> $categories */
/** @var list<array<string, mixed>> $emails */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $review */
use App\Modules\Mail\Classifier;
use App\Modules\Mail\MailService;

$q = static function (array $extra) use ($filters): string {
    $base = array_merge($filters, $extra);
    $base = array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : null) : $v, $base);
    return http_build_query(array_filter($base, static fn ($v) => $v !== null && $v !== ''));
};
$statusBadge = ['new' => 'badge-info', 'open' => 'badge-warn', 'in_progress' => 'badge-warn', 'closed' => 'badge-ok', 'ignored' => 'badge-muted'];
$period = $filters['from'] || $filters['to']
    ? ($filters['from'] ? date('d-m-Y', strtotime($filters['from'])) : '…') . ' to ' . ($filters['to'] ? date('d-m-Y', strtotime($filters['to'])) : 'today')
    : 'all dates';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Mail</p>
        <h1>Inbox</h1>
        <p class="muted small">Emails sorted automatically by keyword rules into the dashboard categories · <?= e($period) ?></p>
    </div>
    <div class="form-actions">
        <?php if ($canManage): ?><a class="btn" href="<?= e(url('mail/settings')) ?>">Mailboxes &amp; rules</a><?php endif; ?>
        <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= e(url('mail/new')) ?>">Add email</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<nav class="mail-cats" aria-label="Categories">
    <a class="card mail-cat<?= $filters['category'] === null ? ' active' : '' ?>" href="?<?= e($q(['category' => null, 'page' => null])) ?>">
        <span>All</span><strong><?= e(array_sum($counts)) ?></strong></a>
    <?php foreach ($categories as $c): ?>
        <a class="card mail-cat mail-<?= e($c['color']) ?><?= $filters['category'] === $c['code'] ? ' active' : '' ?>" href="?<?= e($q(['category' => $c['code'], 'page' => null])) ?>">
            <span><?= e($c['name']) ?></span><strong><?= e($counts[$c['code']] ?? 0) ?></strong></a>
    <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('mail')) ?>" class="filters filters-5 card">
    <label class="field"><span>Search</span><input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Subject, sender, text"></label>
    <label class="field"><span>Status</span>
        <select name="status"><option value="">Any</option>
            <?php foreach (MailService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></label>
    <label class="field"><span>From</span><input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
    <label class="field"><span>To</span><input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
    <div class="filter-actions">
        <?php foreach (['category', 'branch', 'employee', 'customer'] as $k): if ($filters[$k]): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('mail')) ?>">Clear</a>
    </div>
    <div class="filter-checks">
        <label class="check"><input type="checkbox" name="open" value="1"<?= $filters['open'] ? ' checked' : '' ?>> Not closed</label>
        <label class="check"><input type="checkbox" name="mine" value="1"<?= $filters['mine'] ? ' checked' : '' ?>> Assigned to me</label>
        <label class="check"><input type="checkbox" name="review" value="1"<?= $filters['review'] ? ' checked' : '' ?>> Needs review (<?= e($review) ?>)</label>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> email<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($emails === []): ?>
        <p class="empty">No emails match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Received</th><th>From</th><th>Subject</th><th>Category</th><th>Linked to</th><th>Owner</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($emails as $m):
            $unsure = $m['classification_method'] !== 'manual' && (float) ($m['classification_confidence'] ?? 0) < Classifier::REVIEW_BELOW; ?>
            <tr class="<?= $m['status'] === 'new' ? 'unread' : '' ?>">
                <td class="small nowrap"><?= e(date('d-m-Y H:i', strtotime($m['received_at']))) ?></td>
                <td class="small"><?= e($m['from_name'] ?: $m['from_email']) ?><div class="muted"><?= e($m['from_email']) ?></div></td>
                <td><a href="<?= e(url("mail/{$m['id']}")) ?>"><?= e($m['subject'] ?: '(no subject)') ?></a>
                    <?= $m['has_attachments'] ? ' <span class="muted small">📎</span>' : '' ?>
                    <div class="muted small"><?= e(mb_strimwidth((string) $m['body_preview'], 0, 90, '…')) ?></div></td>
                <td class="badge-stack"><span class="badge mail-badge mail-<?= e($m['color'] ?? 'slate') ?>"><?= e($m['category_name'] ?? '—') ?></span>
                    <?php if ($m['classification_method'] === 'manual'): ?><span class="muted small">corrected</span>
                    <?php elseif ($unsure): ?><span class="badge badge-warn">check</span>
                    <?php else: ?><span class="muted small"><?= e(number_format((float) $m['classification_confidence'], 0)) ?>%</span><?php endif; ?></td>
                <td class="small"><?= $m['customer_code'] ? e($m['customer_name']) . ' <span class="muted">' . e($m['customer_code']) . '</span>' : ($m['lead_number'] ? 'Lead ' . e($m['lead_number']) : '<span class="muted">—</span>') ?></td>
                <td class="small"><?= e($m['assignee'] ?? $m['employee'] ?? '—') ?> <span class="muted"><?= e($m['branch_code'] ?? '') ?></span></td>
                <td><span class="badge <?= e($statusBadge[$m['status']] ?? 'badge-muted') ?>"><?= e(MailService::STATUSES[$m['status']]) ?></span></td>
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
