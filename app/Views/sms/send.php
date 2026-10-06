<?php
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var array<string, mixed>|null $preview */
/** @var list<array<string, mixed>> $templates */
/** @var bool $testMode */
use App\Core\Csrf;
use App\Modules\Sms\SmsText;

$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$smsTab = 'send';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SMS</p>
        <h1>Send SMS</h1>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<?php if ($templates): ?>
<nav class="template-picks" aria-label="Start from a template">
    <span class="muted small">Start from a template:</span>
    <?php foreach ($templates as $t): ?><a class="btn btn-sm" href="?template=<?= e($t['id']) ?>"><?= e($t['name']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<form method="post" action="<?= e(url('sms/send')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="template_id" value="<?= e($values['template_id'] ?? '') ?>">
    <label class="field<?= $cls('customer_code') ?>">
        <span>Customer code</span>
        <input type="text" name="customer_code" value="<?= e($values['customer_code'] ?? '') ?>" maxlength="30" class="uppercase" placeholder="e.g. CUS-00001 (fills mobile and details)">
        <?= $err('customer_code') ?>
    </label>
    <label class="field<?= $cls('mobile') ?>">
        <span>Mobile</span>
        <input type="tel" name="mobile" value="<?= e($values['mobile'] ?? '') ?>" maxlength="20" placeholder="Blank = customer's mobile">
        <?= $err('mobile') ?>
    </label>
    <label class="field">
        <span>Name (for {name})</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="150">
    </label>
    <div></div>
    <label class="field field-wide<?= $cls('message') ?>">
        <span>Message *</span>
        <textarea name="message" rows="5" maxlength="1000" data-sms-counter><?= e($values['message'] ?? '') ?></textarea>
        <?= $err('message') ?>
        <span class="muted small">Placeholders: <?= e('{' . implode('} {', array_keys(SmsText::PLACEHOLDERS)) . '}') ?>. With a customer code, {amount} and {invoice_no} come from their oldest overdue bill.</span>
    </label>
    <?php if ($preview): ?>
        <div class="field-wide sms-preview">
            <p class="muted small">Preview to <?= e($preview['mobile']) ?> · <?= e($preview['length']) ?> characters · <strong><?= e($preview['segments']) ?> SMS part(s)</strong> (<?= $preview['encoding'] === 'unicode' ? 'Unicode: 70 per SMS' : 'GSM: 160 per SMS' ?>)</p>
            <p class="sms-bubble"><?= nl2br(e($preview['text'])) ?></p>
        </div>
    <?php endif; ?>
    <div class="field-wide form-actions">
        <button type="submit" name="preview" value="1" class="btn">Preview</button>
        <button type="submit" class="btn btn-primary"><?= $testMode ? 'Send (test)' : 'Send SMS' ?></button>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
