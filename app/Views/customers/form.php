<?php
/** @var array<string, mixed>|null $customer */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $employees */
use App\Core\Csrf;

$isEdit = $customer !== null;
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('customers')) ?>">Customers</a></p>
        <h1><?= $isEdit ? e($customer['name']) : 'Add customer' ?></h1>
        <?php if ($isEdit): ?><p class="muted small"><?= e($customer['customer_code']) ?></p><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url($isEdit ? "customers/{$customer['id']}" : 'customers')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>

    <label class="field<?= $cls('name') ?>">
        <span>Customer name *</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="150" required>
        <?= $err('name') ?>
    </label>
    <label class="field<?= $cls('company_name') ?>">
        <span>Company</span>
        <input type="text" name="company_name" value="<?= e($values['company_name'] ?? '') ?>" maxlength="150">
        <?= $err('company_name') ?>
    </label>

    <label class="field<?= $cls('mobile') ?>">
        <span>Mobile</span>
        <input type="tel" name="mobile" value="<?= e($values['mobile'] ?? '') ?>" maxlength="20">
        <?= $err('mobile') ?>
    </label>
    <label class="field<?= $cls('alternate_mobile') ?>">
        <span>Alternate mobile</span>
        <input type="tel" name="alternate_mobile" value="<?= e($values['alternate_mobile'] ?? '') ?>" maxlength="20">
        <?= $err('alternate_mobile') ?>
    </label>

    <label class="field<?= $cls('email') ?>">
        <span>Email</span>
        <input type="email" name="email" value="<?= e($values['email'] ?? '') ?>" maxlength="150">
        <?= $err('email') ?>
    </label>
    <label class="field<?= $cls('gstin') ?>">
        <span>GSTIN</span>
        <input type="text" name="gstin" value="<?= e($values['gstin'] ?? '') ?>" maxlength="15" class="uppercase">
        <?= $err('gstin') ?>
    </label>

    <label class="field field-wide<?= $cls('address') ?>">
        <span>Address</span>
        <input type="text" name="address" value="<?= e($values['address'] ?? '') ?>" maxlength="255">
        <?= $err('address') ?>
    </label>

    <label class="field<?= $cls('city') ?>">
        <span>City</span>
        <input type="text" name="city" value="<?= e($values['city'] ?? '') ?>" maxlength="80">
        <?= $err('city') ?>
    </label>
    <label class="field<?= $cls('state') ?>">
        <span>State</span>
        <input type="text" name="state" value="<?= e($values['state'] ?? '') ?>" maxlength="80">
        <?= $err('state') ?>
    </label>
    <label class="field<?= $cls('pincode') ?>">
        <span>Pincode</span>
        <input type="text" name="pincode" value="<?= e($values['pincode'] ?? '') ?>" maxlength="6" inputmode="numeric">
        <?= $err('pincode') ?>
    </label>

    <label class="field<?= $cls('branch_id') ?>">
        <span>Branch *</span>
        <select name="branch_id" required>
            <option value="">Choose branch</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= e($b['id']) ?>"<?= (int) ($values['branch_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['branch_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?= $err('branch_id') ?>
    </label>
    <label class="field<?= $cls('employee_id') ?>">
        <span>Assigned sales employee</span>
        <select name="employee_id">
            <option value="">None</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?= e($emp['id']) ?>"<?= (int) ($values['employee_id'] ?? 0) === (int) $emp['id'] ? ' selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?= $err('employee_id') ?>
    </label>

    <label class="field<?= $cls('credit_days') ?>">
        <span>Credit days</span>
        <input type="number" name="credit_days" value="<?= e($values['credit_days'] ?? 30) ?>" min="0" max="365">
        <?= $err('credit_days') ?>
    </label>
    <label class="field<?= $cls('credit_limit') ?>">
        <span>Credit limit (₹)</span>
        <input type="text" name="credit_limit" value="<?= e($values['credit_limit'] ?? '') ?>" inputmode="decimal" placeholder="No limit">
        <?= $err('credit_limit') ?>
    </label>

    <label class="check field-wide">
        <input type="hidden" name="sms_opt_out" value="0">
        <input type="checkbox" name="sms_opt_out" value="1"<?= !empty($values['sms_opt_out']) ? ' checked' : '' ?>>
        This customer has asked NOT to receive SMS
    </label>

    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create customer' ?></button>
        <a class="btn" href="<?= e(url($isEdit ? "customers/{$customer['id']}" : 'customers')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
