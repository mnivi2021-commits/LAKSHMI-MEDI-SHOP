<?php
/** @var array<string, mixed>|null $emp */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $departments */
/** @var list<array<string, mixed>> $designations */
/** @var list<array<string, mixed>> $managers */
/** @var array<string, string> $roles */
/** @var list<array<string, mixed>> $coordinators */
/** @var list<string> $areas */
use App\Core\Csrf;
use App\Modules\Hrm\EmployeeController;

$isEdit = $emp !== null;
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$select = static function (string $name, array $rows, string $empty, callable $label) use ($values): string {
    $html = '<select name="' . e($name) . '"><option value="">' . e($empty) . '</option>';
    foreach ($rows as $r) {
        $sel = (int) ($values[$name] ?? 0) === (int) $r['id'] ? ' selected' : '';
        $html .= '<option value="' . e($r['id']) . '"' . $sel . '>' . e($label($r)) . '</option>';
    }
    return $html . '</select>';
};
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('hrm')) ?>">Employees</a></p>
        <h1><?= $isEdit ? e($emp['name']) : 'Add employee' ?></h1>
        <p class="muted small"><?= $isEdit ? e($emp['employee_code']) : 'The employee code is assigned automatically.' ?></p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url($isEdit ? "hrm/{$emp['id']}" : 'hrm')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>

    <label class="field<?= $cls('name') ?>">
        <span>Full name *</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="120" required>
        <?= $err('name') ?>
    </label>
    <label class="field<?= $cls('short_name') ?>">
        <span>Short name (dashboard)</span>
        <input type="text" name="short_name" value="<?= e($values['short_name'] ?? '') ?>" maxlength="40" class="uppercase" placeholder="e.g. JANA">
        <?= $err('short_name') ?>
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

    <label class="field<?= $cls('branch_id') ?>">
        <span>Branch *</span>
        <?= $select('branch_id', $branches, 'Choose branch', static fn ($b) => "{$b['name']} ({$b['branch_code']})") ?>
        <?= $err('branch_id') ?>
    </label>
    <label class="field<?= $cls('reporting_manager_id') ?>">
        <span>Reporting manager</span>
        <?= $select('reporting_manager_id', $managers, 'None', static fn ($m) => "{$m['name']} ({$m['employee_code']}, {$m['branch_code']})") ?>
        <?= $err('reporting_manager_id') ?>
    </label>

    <label class="field<?= $cls('department_id') ?>">
        <span>Department</span>
        <?= $select('department_id', $departments, 'None', static fn ($d) => $d['name']) ?>
        <?= $err('department_id') ?>
    </label>
    <label class="field<?= $cls('designation_id') ?>">
        <span>Designation</span>
        <?= $select('designation_id', $designations, 'None', static fn ($d) => $d['name']) ?>
        <?= $err('designation_id') ?>
    </label>

    <label class="field<?= $cls('date_of_birth') ?>">
        <span>Date of birth</span>
        <input type="date" name="date_of_birth" value="<?= e($values['date_of_birth'] ?? '') ?>" max="<?= e(date('Y-m-d')) ?>">
        <?= $err('date_of_birth') ?>
        <?php if (!empty($values['date_of_birth']) && strtotime((string) $values['date_of_birth'])): ?><span class="muted small">Age <?= e((new DateTimeImmutable((string) $values['date_of_birth']))->diff(new DateTimeImmutable('today'))->y) ?></span><?php endif; ?>
    </label>
    <div></div>

    <label class="field<?= $cls('joining_date') ?>">
        <span>Joining date</span>
        <input type="date" name="joining_date" value="<?= e($values['joining_date'] ?? '') ?>">
        <?= $err('joining_date') ?>
    </label>
    <label class="field<?= $cls('relieving_date') ?>">
        <span>Relieving date</span>
        <input type="date" name="relieving_date" value="<?= e($values['relieving_date'] ?? '') ?>">
        <?= $err('relieving_date') ?>
    </label>

    <label class="field<?= $cls('status') ?>">
        <span>Status</span>
        <select name="status">
            <?php foreach (EmployeeController::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($values['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <?= $err('status') ?>
        <span class="muted small">Marking an employee Resigned disables their CRM login.</span>
    </label>
    <label class="check">
        <input type="hidden" name="is_sales_rep" value="0">
        <input type="checkbox" name="is_sales_rep" value="1"<?= !empty($values['is_sales_rep']) ? ' checked' : '' ?>>
        Sales representative (shown in the dashboard rep section)
    </label>


    <fieldset class="field-wide req-step">
        <legend>Sales person details</legend>
        <div class="req-row">
            <label class="field<?= $cls('sales_role') ?>">
                <span>Sales role</span>
                <select name="sales_role">
                    <option value="">Not in the sales team</option>
                    <?php foreach ($roles as $k => $l): ?><option value="<?= e($k) ?>"<?= ($values['sales_role'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
                <?= $err('sales_role') ?>
            </label>
            <label class="field<?= $cls('area') ?>">
                <span>Area</span>
                <input type="text" name="area" value="<?= e($values['area'] ?? '') ?>" maxlength="80" list="area-list" placeholder="e.g. Trichy">
                <datalist id="area-list"><?php foreach ($areas as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist>
                <?= $err('area') ?>
            </label>
            <label class="field<?= $cls('coordinator_id') ?>">
                <span>Sales coordinator</span>
                <?= $select('coordinator_id', $coordinators, 'None', static fn ($c) => "{$c['name']} ({$c['branch_code']})") ?>
                <?= $err('coordinator_id') ?>
            </label>
        </div>
    </fieldset>

    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create employee' ?></button>
        <a class="btn" href="<?= e(url('hrm')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
