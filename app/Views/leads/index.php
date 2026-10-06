<?php
/** @var list<array<string, mixed>> $leads */
/** @var array<string, mixed> $filters */
/** @var array<string, array{n: int, value: string}> $pipeline */
/** @var list<array<string, mixed>> $sources */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Money;
use App\Modules\Leads\LeadController;

$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== '' && $v !== null));
$statusBadge = ['won' => 'badge-ok', 'lost' => 'badge-fail', 'new' => 'badge-muted'];
$priorityBadge = ['urgent' => 'badge-fail', 'high' => 'badge-warn'];
$now = date('Y-m-d H:i:s');
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Leads</p>
        <h1>Leads</h1>
    </div>
    <div class="form-actions">
        <?php if ($canExport): ?><a class="btn" href="<?= e(url('leads/export')) ?>">Export CSV</a><?php endif; ?>
        <?php if ($canAdd): ?><a class="btn btn-primary" href="<?= e(url('leads/new')) ?>">Add lead</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<nav class="pipeline card" aria-label="Lead pipeline">
    <?php foreach (LeadController::STATUSES as $key => $label): $n = (int) ($pipeline[$key]['n'] ?? 0); ?>
        <a href="<?= e(url('leads') . '?' . $query(['status' => $filters['status'] === $key ? '' : $key, 'page' => null])) ?>"
           class="pipeline-step pipeline-<?= e($key) ?><?= $filters['status'] === $key ? ' active' : '' ?>">
            <span class="pipeline-label"><?= e($label) ?></span>
            <strong><?= e($n) ?></strong>
            <?php if ($n > 0 && !in_array($key, ['lost'], true)): ?><small><?= e(rupees_short(Money::fromDb($pipeline[$key]['value']))) ?></small><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('leads')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Lead no, name, company, mobile, email">
    </label>
    <label class="field">
        <span>Priority</span>
        <select name="priority">
            <option value="">Any</option>
            <?php foreach (LeadController::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['priority'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Source</span>
        <select name="source">
            <option value="">Any</option>
            <?php foreach ($sources as $s): ?><option value="<?= e($s['id']) ?>"<?= (int) $s['id'] === $filters['source'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <label class="check"><input type="checkbox" name="due" value="1"<?= $filters['due'] === '1' ? ' checked' : '' ?>> Follow-up due</label>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('leads')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> lead<?= $total === 1 ? '' : 's' ?><?= $filters['status'] !== '' ? ' · ' . e(LeadController::STATUSES[$filters['status']]) : '' ?></h2>
    <?php if ($leads === []): ?>
        <p class="empty">No leads match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Lead</th><th>Contact</th><th>Source / product</th><th>Owner</th><th>Status</th><th class="right">Expected</th><th>Next follow-up</th></tr></thead>
        <tbody>
        <?php foreach ($leads as $l): $overdue = $l['next_followup_at'] && $l['next_followup_at'] < $now && !in_array($l['status'], ['won', 'lost'], true); ?>
            <tr>
                <td><a href="<?= e(url("leads/{$l['id']}")) ?>"><strong><?= e($l['name']) ?></strong></a>
                    <div class="muted small"><?= e($l['lead_number']) ?><?= $l['company_name'] ? ' · ' . e($l['company_name']) : '' ?></div></td>
                <td class="small"><?= e($l['mobile'] ?? '—') ?><?= $l['email'] ? '<div class="muted">' . e($l['email']) . '</div>' : '' ?></td>
                <td class="small"><?= e($l['source_name'] ?? '—') ?><?= $l['product_name'] ? '<div class="muted">' . e($l['product_name']) . '</div>' : '' ?></td>
                <td class="small"><?= e($l['employee'] ?? '—') ?> <span class="muted"><?= e($l['branch_code']) ?></span></td>
                <td class="badge-stack"><span class="badge <?= e($statusBadge[$l['status']] ?? 'badge-info') ?>"><?= e(LeadController::STATUSES[$l['status']]) ?></span>
                    <span class="badge <?= e($priorityBadge[$l['priority']] ?? 'badge-muted') ?>"><?= e(LeadController::PRIORITIES[$l['priority']]) ?></span></td>
                <td class="right num"><?= $l['expected_value'] !== null ? e(rupees(Money::fromDb($l['expected_value']))) : '—' ?></td>
                <td class="small nowrap<?= $overdue ? ' neg' : '' ?>"><?= $l['next_followup_at'] ? e(date('d-m-Y H:i', strtotime($l['next_followup_at']))) . ($overdue ? ' (due)' : '') : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
