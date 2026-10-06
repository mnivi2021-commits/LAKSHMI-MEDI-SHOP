<?php
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $templates */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $employees */
use App\Core\Csrf;
use App\Modules\Sms\SmsService;
use App\Modules\Sms\SmsText;

$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('sms/campaigns')) ?>">SMS campaigns</a></p>
        <h1>New campaign</h1>
        <p class="muted small">Saved as a draft first: you will see exactly who receives it and sample messages before anything is sent.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url('sms/campaigns')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>
    <label class="field<?= $cls('name') ?>"><span>Campaign name *</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="150"><?= $err('name') ?></label>
    <label class="field<?= $cls('target_type') ?>"><span>Send to *</span>
        <select name="target_type"><?php foreach (SmsService::TARGETS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($values['target_type'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('target_type') ?></label>
    <label class="field<?= $cls('branch_id') ?>"><span>Branch</span>
        <select name="branch_id"><option value="">All my branches</option><?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= (int) ($values['branch_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select><?= $err('branch_id') ?></label>
    <label class="field<?= $cls('employee_id') ?>"><span>Sales employee</span>
        <select name="employee_id"><option value="">Any</option><?php foreach ($employees as $em): ?><option value="<?= e($em['id']) ?>"<?= (int) ($values['employee_id'] ?? 0) === (int) $em['id'] ? ' selected' : '' ?>><?= e(($em['short_name'] ?: $em['name']) . ' - ' . $em['name']) ?></option><?php endforeach; ?></select><?= $err('employee_id') ?></label>
    <label class="check field-wide"><input type="hidden" name="overdue_only" value="0"><input type="checkbox" name="overdue_only" value="1"<?= !empty($values['overdue_only']) ? ' checked' : '' ?>> Customers: only those with overdue bills (for payment reminders)</label>
    <label class="field"><span>Template</span>
        <select name="template_id"><option value="">— Own text below —</option><?php foreach ($templates as $t): ?><option value="<?= e($t['id']) ?>"<?= (int) ($values['template_id'] ?? 0) === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?> (<?= e($t['category']) ?>)</option><?php endforeach; ?></select></label>
    <label class="field<?= $cls('scheduled_at') ?>"><span>Schedule (optional)</span>
        <input type="datetime-local" name="scheduled_at" value="<?= e($values['scheduled_at'] ?? '') ?>"><?= $err('scheduled_at') ?></label>
    <label class="field field-wide<?= $cls('message') ?>"><span>Own text (leave empty to use the template)</span>
        <textarea name="message" rows="4" maxlength="1000"><?= e($values['message'] ?? '') ?></textarea><?= $err('message') ?>
        <span class="muted small">Placeholders: <?= e('{' . implode('} {', array_keys(SmsText::PLACEHOLDERS)) . '}') ?>. Own text counts as promotional (09:00–21:00 only). Customers who opted out never receive campaigns.</span></label>
    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary">Save draft and preview</button>
        <a class="btn" href="<?= e(url('sms/campaigns')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
