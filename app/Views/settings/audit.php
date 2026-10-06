<?php
/** @var array<string, mixed> $filters */
/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var list<string> $modules */
/** @var list<array<string, mixed>> $users */
$settingsTab = 'audit';
$q = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== null && $v !== ''));
// One line per changed field: "status: active -> resigned"
$diff = static function (?string $old, ?string $new): string {
    $o = json_decode((string) $old, true);
    $n = json_decode((string) $new, true);
    $o = is_array($o) ? $o : [];
    $n = is_array($n) ? $n : [];
    $lines = [];
    foreach (array_unique(array_merge(array_keys($o), array_keys($n))) as $k) {
        $a = array_key_exists($k, $o) ? (is_scalar($o[$k]) || $o[$k] === null ? (string) ($o[$k] ?? '—') : json_encode($o[$k])) : null;
        $b = array_key_exists($k, $n) ? (is_scalar($n[$k]) || $n[$k] === null ? (string) ($n[$k] ?? '—') : json_encode($n[$k])) : null;
        if ($a === $b) {
            continue;
        }
        $lines[] = $k . ': ' . ($a !== null ? mb_strimwidth($a, 0, 60, '…') . ' → ' : '') . mb_strimwidth((string) ($b ?? '(removed)'), 0, 60, '…');
    }
    return implode("\n", array_slice($lines, 0, 8)) . (count($lines) > 8 ? "\n…" : '');
};
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Settings</p>
        <h1>Audit log</h1>
        <p class="muted small">Every sign-in, change, import, export and permission change. The log is read-only.</p>
    </div>
    <div class="form-actions"><a class="btn" href="<?= e(url('settings/audit/export') . '?' . $q([])) ?>">Export CSV</a></div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<form method="get" action="<?= e(url('settings/audit')) ?>" class="filters filters-5 card">
    <label class="field"><span>From</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="field"><span>To</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <label class="field"><span>User</span><select name="user"><option value="">Anyone</option>
        <?php foreach ($users as $u): ?><option value="<?= e($u['user_id']) ?>"<?= (int) $u['user_id'] === $filters['user'] ? ' selected' : '' ?>><?= e($u['user_name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Module</span><select name="module"><option value="">All</option>
        <?php foreach ($modules as $m): ?><option value="<?= e($m) ?>"<?= $filters['module'] === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
    <div class="filter-actions"><button type="submit" class="btn btn-primary">Filter</button><a class="btn" href="<?= e(url('settings/audit')) ?>">Clear</a></div>
    <div class="filter-checks">
        <label class="field"><span>Action contains</span><input type="search" name="action" value="<?= e($filters['action']) ?>" placeholder="e.g. login, deleted, exported"></label>
        <label class="field"><span>Record id</span><input type="search" name="record" value="<?= e($filters['record'] ?? '') ?>" inputmode="numeric"></label>
    </div>
</form>

<section class="card table-card">
    <h2><?= e(number_format($total)) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></h2>
    <?php if ($rows === []): ?>
        <p class="empty">No entries match.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>What changed</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $d = $diff($r['old_data'], $r['new_data']); ?>
            <tr>
                <td class="small nowrap"><?= e(date('d-m-Y H:i:s', strtotime($r['created_at']))) ?></td>
                <td class="small"><?= e($r['user_name'] ?? 'system') ?><div class="muted"><?= e($r['role_slug'] ?? '') ?></div></td>
                <td class="small"><strong><?= e($r['action']) ?></strong><div class="muted"><?= e($r['module']) ?></div></td>
                <td class="small num"><?= $r['record_id'] !== null ? '<a href="?' . e($q(['module' => $r['module'], 'record' => $r['record_id'], 'page' => null])) . '">#' . e($r['record_id']) . '</a>' : '—' ?></td>
                <td class="small audit-diff"><?= $d !== '' ? nl2br(e($d)) : '<span class="muted">—</span>' ?></td>
                <td class="small muted"><?= e($r['ip_address'] ?? '') ?></td>
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
