<?php
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $accounts */
use App\Core\Csrf;

$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('mail')) ?>">Mail</a></p>
        <h1>Add email</h1>
        <p class="muted small">Copy an email you received. It is sorted into a category automatically; you can correct it afterwards.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url('mail')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>
    <label class="field<?= $cls('account_id') ?>">
        <span>Received in mailbox *</span>
        <select name="account_id">
            <?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"<?= (int) ($values['account_id'] ?? 0) === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['display_name'] ?: $a['email_address']) ?></option><?php endforeach; ?>
        </select>
        <?= $err('account_id') ?>
    </label>
    <label class="field<?= $cls('received_at') ?>">
        <span>Received at *</span>
        <input type="datetime-local" name="received_at" value="<?= e($values['received_at'] ?? '') ?>">
        <?= $err('received_at') ?>
    </label>
    <label class="field<?= $cls('from_email') ?>">
        <span>From email *</span>
        <input type="email" name="from_email" value="<?= e($values['from_email'] ?? '') ?>" maxlength="150">
        <?= $err('from_email') ?>
    </label>
    <label class="field">
        <span>From name</span>
        <input type="text" name="from_name" value="<?= e($values['from_name'] ?? '') ?>" maxlength="150">
    </label>
    <label class="field field-wide<?= $cls('subject') ?>">
        <span>Subject</span>
        <input type="text" name="subject" value="<?= e($values['subject'] ?? '') ?>" maxlength="500">
        <?= $err('subject') ?>
    </label>
    <label class="field field-wide">
        <span>Message</span>
        <textarea name="body" rows="8" maxlength="5000"><?= e($values['body'] ?? '') ?></textarea>
        <span class="muted small">Only the first 1,000 characters are kept.</span>
    </label>
    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary">Add and classify</button>
        <a class="btn" href="<?= e(url('mail')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
