<?php
/** @var array<string, mixed> $batch */
/** @var \App\Modules\Imports\Importer $imp */
/** @var list<string> $headers */
/** @var array<string, string> $mapping */
/** @var list<array<string, string>> $samples */
use App\Core\Csrf;

ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('imports')) ?>">Excel Upload</a> · <?= e($imp->label()) ?></p>
        <h1>Match columns</h1>
        <p class="muted small"><?= e($batch['original_filename']) ?> · <?= e($batch['total_rows']) ?> data rows. Columns with matching headings were chosen for you; check them.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url("imports/{$batch['id']}/map")) ?>" class="card table-card">
    <?= Csrf::field() ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>CRM field</th><th>Column in your file</th><th>First rows in that column</th></tr></thead>
        <tbody>
        <?php foreach ($imp->fields() as $field => $f): $chosen = $mapping[$field] ?? ''; ?>
            <tr>
                <td><strong><?= e($f['label']) ?></strong><?= ($f['required'] ?? false) ? ' <span class="badge badge-warn">Required</span>' : '' ?>
                    <div class="muted small"><?= e($f['format'] ?? '') ?></div></td>
                <td>
                    <select name="map[<?= e($field) ?>]" aria-label="Column for <?= e($f['label']) ?>">
                        <option value="">— Not in file —</option>
                        <?php foreach ($headers as $h): ?><option value="<?= e($h) ?>"<?= $h === $chosen ? ' selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?>
                    </select>
                </td>
                <td class="small muted">
                    <?php if ($chosen !== ''): ?>
                        <?= e(implode(' · ', array_map(static fn ($s) => ($s[$chosen] ?? '') === '' ? '(blank)' : mb_strimwidth((string) $s[$chosen], 0, 30, '…'), $samples))) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="form-actions padded">
        <button type="submit" class="btn btn-primary">Check all rows</button>
        <a class="btn" href="<?= e(url("imports/{$batch['id']}")) ?>">Back</a>
    </div>
</form>

<?php $unused = array_diff($headers, array_values($mapping)); if ($unused): ?>
    <p class="muted small">Columns in your file not used: <?= e(implode(', ', $unused)) ?>.</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
