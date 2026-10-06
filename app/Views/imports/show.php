<?php
/** @var array<string, mixed> $batch */
/** @var \App\Modules\Imports\Importer $imp */
/** @var list<array<string, mixed>> $rows */
/** @var string $filter */
/** @var int $page */
/** @var int $pages */
/** @var int $records */
/** @var array<string, string> $mapping */
use App\Core\Csrf;

$statusBadge = ['valid' => 'badge-ok', 'imported' => 'badge-ok', 'invalid' => 'badge-fail', 'duplicate' => 'badge-warn'];
$statusText = ['valid' => 'Ready', 'imported' => 'Imported', 'invalid' => 'Error', 'duplicate' => 'Duplicate', 'pending' => 'Not checked', 'skipped' => 'Skipped'];
// Show the first mapped columns (up to 6) so each row can be recognised.
$cols = array_slice(array_filter($imp->fields(), static fn ($f, $k) => isset($mapping[$k]), ARRAY_FILTER_USE_BOTH), 0, 6, true);
$isOpen = in_array($batch['status'], ['uploaded', 'mapped', 'validated'], true);
$problems = (int) $batch['invalid_rows'] + (int) $batch['duplicate_rows'];
$unit = $imp->groupField() !== null ? 'document' : 'record';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('imports')) ?>">Excel Upload</a> · <?= e($imp->label()) ?> · #<?= e($batch['id']) ?></p>
        <h1><?= e($batch['original_filename']) ?></h1>
        <p class="muted small">Uploaded <?= e(date('d-m-Y H:i', strtotime($batch['created_at']))) ?> · status <strong><?= e(ucfirst($batch['status'])) ?></strong><?= $batch['completed_at'] ? ' · imported ' . e(date('d-m-Y H:i', strtotime($batch['completed_at']))) : '' ?></p>
    </div>
    <div class="form-actions">
        <?php if ($isOpen): ?><a class="btn" href="<?= e(url("imports/{$batch['id']}/map")) ?>">Change column matching</a><?php endif; ?>
        <?php if ($problems > 0): ?><a class="btn" href="<?= e(url("imports/{$batch['id']}/errors")) ?>">Download problem rows</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php if ($batch['error_message']): ?><div class="alert alert-error"><?= e($batch['error_message']) ?></div><?php endif; ?>

<section class="import-stats">
    <a class="card stat<?= $filter === '' ? ' active' : '' ?>" href="<?= e(url("imports/{$batch['id']}")) ?>"><span>Rows in file</span><strong><?= e($batch['total_rows']) ?></strong></a>
    <?php if ($batch['status'] === 'completed'): ?>
        <a class="card stat stat-ok<?= $filter === 'imported' ? ' active' : '' ?>" href="?status=imported"><span>Imported</span><strong><?= e($batch['imported_rows']) ?></strong></a>
    <?php else: ?>
        <a class="card stat stat-ok<?= $filter === 'valid' ? ' active' : '' ?>" href="?status=valid"><span>Ready to import</span><strong><?= e($batch['valid_rows']) ?></strong></a>
    <?php endif; ?>
    <a class="card stat stat-fail<?= $filter === 'invalid' ? ' active' : '' ?>" href="?status=invalid"><span>With errors</span><strong><?= e($batch['invalid_rows']) ?></strong></a>
    <a class="card stat stat-warn<?= $filter === 'duplicate' ? ' active' : '' ?>" href="?status=duplicate"><span>Duplicates (skipped)</span><strong><?= e($batch['duplicate_rows']) ?></strong></a>
</section>

<?php if ($batch['status'] === 'validated' && (int) $batch['valid_rows'] > 0): ?>
    <form method="post" action="<?= e(url("imports/{$batch['id']}/run")) ?>" class="card import-run"
          data-confirm="Import <?= e($records) ?> <?= e($unit) ?>(s) from <?= e($batch['valid_rows']) ?> row(s)?">
        <?= Csrf::field() ?>
        <div>
            <strong>Import <?= e($records) ?> <?= e($unit) ?><?= $records === 1 ? '' : 's' ?></strong> from <?= e($batch['valid_rows']) ?> ready row<?= (int) $batch['valid_rows'] === 1 ? '' : 's' ?>.
            <?php if ($problems > 0): ?><div class="muted small"><?= e($problems) ?> row(s) with errors or duplicates will be left out. Fix them in your file and upload again later if needed.</div><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Import now</button>
    </form>
<?php elseif ($batch['status'] === 'validated'): ?>
    <div class="alert alert-error">No rows are ready to import. Download the problem rows, correct the file and upload it again.</div>
<?php elseif (in_array($batch['status'], ['uploaded', 'mapped'], true)): ?>
    <div class="alert alert-info">Match the columns to check the rows. <a href="<?= e(url("imports/{$batch['id']}/map")) ?>">Match columns</a></div>
<?php endif; ?>

<section class="card table-card">
    <h2>Rows<?= $filter !== '' ? ' · ' . e($statusText[$filter]) : '' ?></h2>
    <?php if ($rows === []): ?>
        <p class="empty">No rows here.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Row</th><th>Status</th><?php foreach ($cols as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?><th>Problem</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $m = json_decode((string) ($r['mapped_data'] ?? ''), true) ?: []; $errs = json_decode((string) ($r['errors'] ?? ''), true) ?: []; ?>
            <tr>
                <td class="num"><?= e($r['row_no']) ?></td>
                <td><span class="badge <?= e($statusBadge[$r['status']] ?? 'badge-muted') ?>"><?= e($statusText[$r['status']] ?? $r['status']) ?></span></td>
                <?php foreach (array_keys($cols) as $k): ?><td class="small"><?= e(mb_strimwidth((string) ($m[$k] ?? ''), 0, 40, '…')) ?></td><?php endforeach; ?>
                <td class="small<?= $r['status'] === 'invalid' ? ' neg' : '' ?>"><?= e(implode(' ', $errs)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_filter(['status' => $filter, 'page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_filter(['status' => $filter, 'page' => $page + 1]))) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if ($isOpen): ?>
    <form method="post" action="<?= e(url("imports/{$batch['id']}/cancel")) ?>" data-confirm="Cancel this import? Nothing will be saved.">
        <?= Csrf::field() ?><button type="submit" class="btn btn-sm">Cancel this import</button>
    </form>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
