<?php
/** @var array<string, string> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $years */
/** @var string|null $importedAsOn */
use App\Core\Csrf;
use App\Modules\Settings\SettingsController;

$settingsTab = 'settings';
$name = static fn (string $k): string => str_replace('.', '__', $k);
$display = static function (string $k, string $v): string {
    if ($k === 'outstanding.aging_buckets' && ($arr = json_decode($v, true)) && is_array($arr)) {
        return implode(', ', $arr);
    }
    return $v;
};
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Settings</p>
        <h1>Settings</h1>
        <p class="muted small">Business choices only. Passwords and API keys (database, SMS, mail) are kept in the server's .env file and are never shown here.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<form method="post" action="<?= e(url('settings')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>
    <?php foreach (SettingsController::FIELDS as $k => [$label, $type, $choices, $help]): $err = $errors[$k] ?? null; ?>
        <label class="field field-wide<?= $err ? ' has-error' : '' ?>">
            <span><?= e($label) ?></span>
            <?php if ($type === 'choice'): ?>
                <select name="<?= e($name($k)) ?>">
                    <?php foreach ($choices as $v => $l): ?><option value="<?= e($v) ?>"<?= ($values[$k] ?? '') === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" name="<?= e($name($k)) ?>" value="<?= e($display($k, (string) ($values[$k] ?? ''))) ?>" maxlength="100">
            <?php endif; ?>
            <?php if ($err): ?><span class="field-error"><?= e($err) ?></span><?php endif; ?>
            <span class="muted small"><?= e($help) ?><?= $k === 'outstanding.source' ? ' Latest statement uploaded: ' . ($importedAsOn ? e(date('d-m-Y', strtotime($importedAsOn))) : 'none') . '.' : '' ?></span>
        </label>
    <?php endforeach; ?>
    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
</form>

<section class="card table-card" id="years">
    <h2>Financial years</h2>
    <p class="muted small padded">The dashboard picks the year from the date. Lock a finished year after closing the books: nothing can then be entered, imported or quick-added with a date in it.</p>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Year</th><th>From</th><th>To</th><th class="right">Invoices</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($years as $y): ?>
            <tr>
                <td><strong><?= e($y['label']) ?></strong></td>
                <td><?= e(date('d-m-Y', strtotime($y['start_date']))) ?></td>
                <td><?= e(date('d-m-Y', strtotime($y['end_date']))) ?></td>
                <td class="right num"><?= e($y['invoices']) ?></td>
                <td><?= $y['is_running'] ? '<span class="badge badge-info">Current</span> ' : '' ?><?= $y['is_locked'] ? '<span class="badge badge-muted">Locked</span>' : '<span class="badge badge-ok">Open</span>' ?></td>
                <td class="right">
                    <?php if ($y['is_locked'] || $y['end_date'] < date('Y-m-d')): ?>
                        <form method="post" action="<?= e(url("settings/years/{$y['id']}/lock")) ?>" data-confirm="<?= e(($y['is_locked'] ? 'Unlock ' : 'Lock ') . $y['label'] . '?') ?>">
                            <?= Csrf::field() ?><button type="submit" class="btn btn-sm"><?= $y['is_locked'] ? 'Unlock' : 'Lock' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <form method="post" action="<?= e(url('settings/years')) ?>" class="padded">
        <?= Csrf::field() ?><button type="submit" class="btn">Add next financial year</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
