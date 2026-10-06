<?php
/** @var array<string, mixed>|null $product */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<string> $gstRates */
/** @var list<string> $categories */
use App\Core\Csrf;

$isEdit = $product !== null;
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$currentGst = rtrim(rtrim((string) ($values['gst_rate'] ?? '18'), '0'), '.') ?: '0';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('products')) ?>">Products</a></p>
        <h1><?= $isEdit ? e($product['name']) : 'Add product' ?></h1>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url($isEdit ? "products/{$product['id']}" : 'products')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>

    <label class="field<?= $cls('product_code') ?>">
        <span>Product code *</span>
        <input type="text" name="product_code" value="<?= e($values['product_code'] ?? '') ?>" maxlength="40" class="uppercase" required>
        <?= $err('product_code') ?>
    </label>
    <label class="field<?= $cls('name') ?>">
        <span>Product name *</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="150" required>
        <?= $err('name') ?>
    </label>

    <label class="field<?= $cls('category') ?>">
        <span>Category</span>
        <input type="text" name="category" value="<?= e($values['category'] ?? '') ?>" maxlength="80" list="product-categories">
        <datalist id="product-categories">
            <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
        </datalist>
        <?= $err('category') ?>
    </label>
    <label class="field<?= $cls('unit') ?>">
        <span>Unit</span>
        <input type="text" name="unit" value="<?= e($values['unit'] ?? 'Nos') ?>" maxlength="20" placeholder="Nos, Kg, Roll, Box">
        <?= $err('unit') ?>
    </label>

    <label class="field<?= $cls('hsn_code') ?>">
        <span>HSN code</span>
        <input type="text" name="hsn_code" value="<?= e($values['hsn_code'] ?? '') ?>" maxlength="8" inputmode="numeric">
        <?= $err('hsn_code') ?>
    </label>
    <label class="field<?= $cls('rate') ?>">
        <span>Rate (₹, before GST)</span>
        <input type="text" name="rate" value="<?= e($values['rate'] ?? '') ?>" inputmode="decimal" placeholder="0.00">
        <?= $err('rate') ?>
    </label>

    <label class="field<?= $cls('gst_rate') ?>">
        <span>GST rate</span>
        <select name="gst_rate">
            <?php foreach ($gstRates as $g): ?>
                <option value="<?= e($g) ?>"<?= $g === $currentGst ? ' selected' : '' ?>><?= e($g) ?>%</option>
            <?php endforeach; ?>
        </select>
        <?= $err('gst_rate') ?>
    </label>

    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
        <a class="btn" href="<?= e(url('products')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
