<?php
/** @var array<string, \App\Modules\Imports\Importer> $available */
/** @var list<array<string, mixed>> $batches */
$statusBadge = ['completed' => 'badge-ok', 'validated' => 'badge-info', 'failed' => 'badge-fail', 'cancelled' => 'badge-muted'];
$labels = array_map(static fn ($i) => $i->label(), $available);
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Data</p>
        <h1>Excel Upload</h1>
        <p class="muted small">Upload .xlsx or CSV files (max 5 MB, 5,000 rows). You will match the columns and see every row checked before anything is saved.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<section class="import-types">
    <?php foreach ($available as $key => $imp): ?>
        <article class="card import-type">
            <h2><?= e($imp->label()) ?></h2>
            <p class="muted small"><?= e(count($imp->fields())) ?> columns · <?= e(count(array_filter($imp->fields(), static fn ($f) => $f['required'] ?? false))) ?> required</p>
            <div class="form-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url("imports/new/{$key}")) ?>">Upload</a>
                <a class="btn btn-sm" href="<?= e(url("imports/template/{$key}")) ?>">Template (.xlsx)</a>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="card table-card">
    <h2>Recent uploads</h2>
    <?php if ($batches === []): ?>
        <p class="empty">No uploads yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>#</th><th>Uploaded</th><th>Type</th><th>File</th><th class="right">Rows</th><th class="right">Ready</th><th class="right">Errors</th><th class="right">Duplicates</th><th class="right">Imported</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td><a href="<?= e(url("imports/{$b['id']}")) ?>"><?= e($b['id']) ?></a></td>
                <td class="small nowrap"><?= e(date('d-m-Y H:i', strtotime($b['created_at']))) ?><div class="muted"><?= e($b['user_name'] ?? '') ?></div></td>
                <td class="small"><?= e($labels[$b['module']] ?? $b['module']) ?></td>
                <td class="small"><a href="<?= e(url("imports/{$b['id']}")) ?>"><?= e($b['original_filename']) ?></a></td>
                <td class="right num"><?= e($b['total_rows']) ?></td>
                <td class="right num"><?= e($b['valid_rows']) ?></td>
                <td class="right num<?= $b['invalid_rows'] > 0 ? ' neg' : '' ?>"><?= e($b['invalid_rows']) ?></td>
                <td class="right num"><?= e($b['duplicate_rows']) ?></td>
                <td class="right num"><?= e($b['imported_rows']) ?></td>
                <td><span class="badge <?= e($statusBadge[$b['status']] ?? 'badge-warn') ?>"><?= e(ucfirst($b['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
