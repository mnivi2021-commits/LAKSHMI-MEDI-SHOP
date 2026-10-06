<?php
/** @var array<string, mixed>|null $user */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $roles */
/** @var list<array<string, mixed>> $employees */
/** @var list<array<string, mixed>> $branches */
/** @var bool $roleLocked */
use App\Core\Csrf;

$activeTab = 'users';
$isEdit = $user !== null;
$scopeHelp = ['all' => 'sees all branches', 'branch' => 'sees ticked branches only', 'team' => 'sees own + reporting team', 'own' => 'sees own records only'];
$selectedBranches = array_map('intval', (array) ($values['branch_ids'] ?? []));
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('access/users')) ?>">Users</a></p>
        <h1><?= $isEdit ? 'Edit ' . e($user['name']) : 'Add user' ?></h1>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/partials/flash.php'; ?>

<form method="post" action="<?= e(url($isEdit ? "access/users/{$user['id']}" : 'access/users')) ?>" class="card form form-grid" novalidate>
    <?= Csrf::field() ?>

    <label class="field<?= $cls('name') ?>">
        <span>Full name *</span>
        <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="100" required>
        <?= $err('name') ?>
    </label>

    <label class="field<?= $cls('username') ?>">
        <span>Username *</span>
        <input type="text" name="username" value="<?= e($values['username'] ?? '') ?>" maxlength="50" autocapitalize="none" spellcheck="false" required>
        <?= $err('username') ?>
    </label>

    <label class="field<?= $cls('email') ?>">
        <span>Email *</span>
        <input type="email" name="email" value="<?= e($values['email'] ?? '') ?>" maxlength="150" required>
        <?= $err('email') ?>
    </label>

    <label class="field<?= $cls('mobile') ?>">
        <span>Mobile</span>
        <input type="tel" name="mobile" value="<?= e($values['mobile'] ?? '') ?>" maxlength="20" inputmode="tel">
        <?= $err('mobile') ?>
    </label>

    <label class="field<?= $cls('role_id') ?>">
        <span>Role *</span>
        <?php if ($roleLocked): ?>
            <input type="text" value="<?= e($user['role_name']) ?>" disabled>
            <span class="muted small">You cannot change your own role.</span>
        <?php else: ?>
            <select name="role_id" required>
                <option value="">Choose a role</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r['id']) ?>"<?= (int) ($values['role_id'] ?? 0) === (int) $r['id'] ? ' selected' : '' ?>>
                        <?= e($r['name']) ?> (<?= e($scopeHelp[$r['data_scope']] ?? $r['data_scope']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?= $err('role_id') ?>
    </label>

    <label class="field<?= $cls('employee_id') ?>">
        <span>Linked employee</span>
        <select name="employee_id">
            <option value="">None (office / admin user)</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?= e($emp['id']) ?>"<?= (int) ($values['employee_id'] ?? 0) === (int) $emp['id'] ? ' selected' : '' ?>>
                    <?= e($emp['name']) ?> · <?= e($emp['employee_code']) ?> · <?= e($emp['branch_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="muted small">Required for "own records" and "own team" roles such as Sales Executive.</span>
        <?= $err('employee_id') ?>
    </label>

    <fieldset class="field field-wide<?= $cls('branch_ids') ?>">
        <legend>Branches this user may see</legend>
        <span class="muted small">Used by "assigned branches" roles (e.g. Branch Manager). Ignored by other roles.</span>
        <div class="check-grid">
            <?php foreach ($branches as $b): ?>
                <label class="check">
                    <input type="checkbox" name="branch_ids[]" value="<?= e($b['id']) ?>"<?= in_array((int) $b['id'], $selectedBranches, true) ? ' checked' : '' ?>>
                    <?= e($b['name']) ?> <span class="muted">(<?= e($b['branch_code']) ?>)</span>
                </label>
            <?php endforeach; ?>
        </div>
        <?= $err('branch_ids') ?>
    </fieldset>

    <?php if (!$isEdit): ?>
        <p class="field-wide muted small">A temporary password is generated and shown once after saving. The user must change it at first sign-in.</p>
    <?php endif; ?>

    <div class="field-wide form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a class="btn" href="<?= e(url('access/users')) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/app.php';
