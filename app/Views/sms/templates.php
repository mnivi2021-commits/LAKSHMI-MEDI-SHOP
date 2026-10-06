<?php
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var array<string, mixed>|null $edit */
/** @var list<array<string, mixed>> $templates */
use App\Core\Csrf;
use App\Modules\Sms\SmsController;
use App\Modules\Sms\SmsText;

$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$smsTab = 'templates';
$testMode = SmsController::testMode();
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SMS</p>
        <h1>Templates</h1>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="card table-card">
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Name</th><th>Category</th><th>Text</th><th class="right">Length</th><th class="right">Used</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): $m = SmsText::measure($t['body']); ?>
            <tr>
                <td><strong><?= e($t['name']) ?></strong><?= $t['dlt_template_id'] ? '<div class="muted small">DLT ' . e($t['dlt_template_id']) . '</div>' : '' ?></td>
                <td class="small"><?= e(SmsController::CATEGORIES[$t['category']] ?? $t['category']) ?></td>
                <td class="small"><?= e($t['body']) ?></td>
                <td class="right small nowrap"><?= e($m['length']) ?> ch · <?= e($m['segments']) ?> SMS</td>
                <td class="right num"><?= e($t['used']) ?></td>
                <td><span class="badge <?= $t['status'] === 'active' ? 'badge-ok' : 'badge-muted' ?>"><?= e(ucfirst($t['status'])) ?></span></td>
                <td class="right"><a class="btn btn-sm" href="?edit=<?= e($t['id']) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<form method="post" action="<?= e(url('sms/templates')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>
    <h2 class="field-wide"><?= $edit ? 'Edit "' . e($edit['name']) . '"' : 'New template' ?></h2>
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
    <label class="field<?= $cls('name') ?>"><span>Name *</span><input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="100"><?= $err('name') ?></label>
    <label class="field<?= $cls('category') ?>"><span>Category *</span>
        <select name="category"><?php foreach (SmsController::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($values['category'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('category') ?></label>
    <label class="field<?= $cls('sender_id') ?>"><span>Sender ID (DLT)</span><input type="text" name="sender_id" value="<?= e($values['sender_id'] ?? '') ?>" maxlength="6" class="uppercase" placeholder="e.g. LKSMED"><?= $err('sender_id') ?></label>
    <label class="field"><span>DLT template ID</span><input type="text" name="dlt_template_id" value="<?= e($values['dlt_template_id'] ?? '') ?>" maxlength="50"></label>
    <label class="field field-wide<?= $cls('body') ?>"><span>Text *</span>
        <textarea name="body" rows="4" maxlength="1000"><?= e($values['body'] ?? '') ?></textarea><?= $err('body') ?>
        <span class="muted small">Placeholders: <?= e('{' . implode('} {', array_keys(SmsText::PLACEHOLDERS)) . '}') ?>. In India the text must match the template registered on DLT.</span></label>
    <label class="field"><span>Status</span><select name="status"><option value="active">Active</option><option value="inactive"<?= ($values['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></label>
    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary">Save template</button>
        <?php if ($edit): ?><a class="btn" href="<?= e(url('sms/templates')) ?>">Cancel</a><?php endif; ?>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
