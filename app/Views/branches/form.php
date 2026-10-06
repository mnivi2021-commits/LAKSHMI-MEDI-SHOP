<?php
/** @var array<string, mixed>|null $branch */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $employees */
use App\Core\Csrf;
use App\Core\Gate;

$isEdit = $branch !== null;
$canEdit = Gate::allows('branches.edit');
$readOnly = !$canEdit;
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('branches')) ?>">Branches</a></p>
        <h1><?= $isEdit ? e($branch['name']) : 'Add branch' ?></h1>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url($isEdit ? "branches/{$branch['id']}" : 'branches')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>

    <label class="field<?= $cls('branch_code') ?>">
        <span>Branch code *</span>
        <input type="text" name="branch_code" value="<?= e($values['branch_code'] ?? '') ?>" maxlength="20" class="uppercase" required<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('branch_code') ?>
    </label>

    <label class="field<?= $cls('name') ?>">
        <span>Branch name *</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="120" required<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('name') ?>
    </label>

    <label class="field field-wide<?= $cls('address') ?>">
        <span>Address</span>
        <input type="text" name="address" value="<?= e($values['address'] ?? '') ?>" maxlength="255"<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('address') ?>
    </label>

    <label class="field<?= $cls('city') ?>">
        <span>City</span>
        <input type="text" name="city" value="<?= e($values['city'] ?? '') ?>" maxlength="80"<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('city') ?>
    </label>

    <label class="field<?= $cls('state') ?>">
        <span>State</span>
        <input type="text" name="state" value="<?= e($values['state'] ?? '') ?>" maxlength="80"<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('state') ?>
    </label>

    <label class="field<?= $cls('pincode') ?>">
        <span>Pincode</span>
        <input type="text" name="pincode" value="<?= e($values['pincode'] ?? '') ?>" maxlength="6" inputmode="numeric"<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('pincode') ?>
    </label>

    <label class="field<?= $cls('contact_number') ?>">
        <span>Contact number</span>
        <input type="tel" name="contact_number" value="<?= e($values['contact_number'] ?? '') ?>" maxlength="20"<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('contact_number') ?>
    </label>

    <label class="field<?= $cls('email') ?>">
        <span>Email</span>
        <input type="email" name="email" value="<?= e($values['email'] ?? '') ?>" maxlength="150"<?= $readOnly ? ' readonly' : '' ?>>
        <?= $err('email') ?>
    </label>

    <label class="field<?= $cls('manager_employee_id') ?>">
        <span>Branch manager</span>
        <?php if (!$isEdit): ?>
            <input type="text" value="Set after the branch has employees" disabled>
        <?php else: ?>
            <select name="manager_employee_id"<?= $readOnly ? ' disabled' : '' ?>>
                <option value="">None</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= e($emp['id']) ?>"<?= (int) ($values['manager_employee_id'] ?? 0) === (int) $emp['id'] ? ' selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php if ($employees === []): ?><span class="muted small">No active employees in this branch yet.</span><?php endif; ?>
        <?php endif; ?>
        <?= $err('manager_employee_id') ?>
    </label>

    <?php if (!$readOnly): ?>
    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create branch' ?></button>
        <a class="btn" href="<?= e(url('branches')) ?>">Cancel</a>
    </div>
    <?php endif; ?>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
