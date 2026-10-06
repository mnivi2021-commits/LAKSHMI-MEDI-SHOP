<?php
/** @var array<string, mixed>|null $lead */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $sources */
/** @var list<array<string, mixed>> $products */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $employees */
use App\Core\Csrf;
use App\Modules\Leads\LeadController;

$isEdit = $lead !== null;
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$followup = $values['next_followup_at'] ?? '';
if ($followup !== '' && $followup !== null && !str_contains((string) $followup, 'T')) {
    $followup = date('Y-m-d\TH:i', strtotime((string) $followup));
}
$selected = static fn (string $field, $id): string => (int) ($values[$field] ?? 0) === (int) $id ? ' selected' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('leads')) ?>">Leads</a></p>
        <h1><?= $isEdit ? e($lead['name']) : 'Add lead' ?></h1>
        <?php if ($isEdit): ?><p class="muted small"><?= e($lead['lead_number']) ?></p><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url($isEdit ? "leads/{$lead['id']}" : 'leads')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>

    <label class="field<?= $cls('name') ?>">
        <span>Lead name *</span>
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
    <label class="field<?= $cls('email') ?>">
        <span>Email</span>
        <input type="email" name="email" value="<?= e($values['email'] ?? '') ?>" maxlength="150">
        <?= $err('email') ?>
    </label>

    <label class="field<?= $cls('source_id') ?>">
        <span>Source</span>
        <select name="source_id">
            <option value="">—</option>
            <?php foreach ($sources as $s): ?><option value="<?= e($s['id']) ?>"<?= $selected('source_id', $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <?= $err('source_id') ?>
    </label>
    <label class="field<?= $cls('product_id') ?>">
        <span>Product of interest</span>
        <select name="product_id">
            <option value="">—</option>
            <?php foreach ($products as $p): ?><option value="<?= e($p['id']) ?>"<?= $selected('product_id', $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <?= $err('product_id') ?>
    </label>

    <label class="field<?= $cls('branch_id') ?>">
        <span>Branch *</span>
        <select name="branch_id" required>
            <option value="">Choose branch</option>
            <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= $selected('branch_id', $b['id']) ?>><?= e($b['name']) ?> (<?= e($b['branch_code']) ?>)</option><?php endforeach; ?>
        </select>
        <?= $err('branch_id') ?>
    </label>
    <label class="field<?= $cls('employee_id') ?>">
        <span>Sales employee</span>
        <select name="employee_id">
            <?php if (count($employees) !== 1): ?><option value="">Unassigned</option><?php endif; ?>
            <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= $selected('employee_id', $emp['id']) ?>><?= e($emp['short_name'] ?: $emp['name']) ?> - <?= e($emp['name']) ?></option><?php endforeach; ?>
        </select>
        <?= $err('employee_id') ?>
    </label>

    <label class="field<?= $cls('priority') ?>">
        <span>Priority</span>
        <select name="priority">
            <?php foreach (LeadController::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($values['priority'] ?? 'medium') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <?= $err('priority') ?>
    </label>
    <label class="field<?= $cls('expected_value') ?>">
        <span>Expected value (₹)</span>
        <input type="text" name="expected_value" value="<?= e($values['expected_value'] ?? '') ?>" inputmode="decimal">
        <?= $err('expected_value') ?>
    </label>

    <label class="field<?= $cls('next_followup_at') ?>">
        <span>Next follow-up</span>
        <input type="datetime-local" name="next_followup_at" value="<?= e($followup ?? '') ?>">
        <?= $err('next_followup_at') ?>
    </label>

    <label class="field field-wide<?= $cls('remarks') ?>">
        <span>Remarks</span>
        <textarea name="remarks" rows="3" maxlength="2000"><?= e($values['remarks'] ?? '') ?></textarea>
        <?= $err('remarks') ?>
    </label>

    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create lead' ?></button>
        <a class="btn" href="<?= e(url($isEdit ? "leads/{$lead['id']}" : 'leads')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
