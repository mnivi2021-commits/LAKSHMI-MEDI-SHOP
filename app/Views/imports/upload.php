<?php
/** @var \App\Modules\Imports\Importer $imp */
use App\Core\Csrf;

ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('imports')) ?>">Excel Upload</a></p>
        <h1>Upload <?= e(strtolower($imp->label())) ?></h1>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url("imports/template/{$imp->key()}")) ?>">Download template (.xlsx)</a>
        <a class="btn" href="<?= e(url("imports/template/{$imp->key()}") . '?format=csv') ?>">CSV template</a>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url("imports/new/{$imp->key()}")) ?>" enctype="multipart/form-data" class="card form">
    <?= Csrf::field() ?>
    <label class="field">
        <span>Excel (.xlsx) or CSV file *</span>
        <input type="file" name="file" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
        <span class="muted small">Max 5 MB and 5,000 rows. The first row must contain the column headings. Only the first sheet is read.</span>
    </label>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Upload and match columns</button>
        <a class="btn" href="<?= e(url('imports')) ?>">Cancel</a>
    </div>
    <p class="muted small">Nothing is saved to the CRM until you have reviewed the checked rows and pressed Import.</p>
</form>

<section class="card table-card">
    <h2>Columns</h2>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Column</th><th>Required</th><th>Format</th><th>Also recognised as</th></tr></thead>
        <tbody>
        <?php foreach ($imp->fields() as $f): ?>
            <tr>
                <td><strong><?= e($f['label']) ?></strong></td>
                <td><?= ($f['required'] ?? false) ? '<span class="badge badge-warn">Required</span>' : '' ?></td>
                <td class="small"><?= e($f['format'] ?? '') ?></td>
                <td class="small muted"><?= e(implode(', ', $f['aliases'] ?? [])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($imp->notes()): ?>
        <ul class="padded small notes-list">
            <?php foreach ($imp->notes() as $n): ?><li><?= e($n) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
