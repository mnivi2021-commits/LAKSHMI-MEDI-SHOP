<?php
/** @var array<string, mixed>|null $role */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var bool $isSuper */
/** @var array<string, array{label: string, items: array<string, array{id: int, slug: string, critical: bool}>}> $catalogue */
/** @var list<string> $columns */
/** @var array<string, true> $checked */
/** @var array<string, true> $actorHolds */
/** @var int $userCount */
use App\Core\Csrf;

$activeTab = 'roles';
$isEdit = $role !== null;
$isSystem = $isEdit && (int) $role['is_system'] === 1;
$scopes = [
    'all'    => ['All branches', 'Every branch, employee and customer'],
    'branch' => ['Assigned branches', 'Only branches ticked on the user'],
    'team'   => ['Own team', 'The user\'s employee and everyone reporting to them'],
    'own'    => ['Own records', 'Only records of the user\'s own employee'],
];
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('access/roles')) ?>">Roles &amp; permissions</a></p>
        <h1><?= $isEdit ? e($role['name']) : 'New role' ?></h1>
        <?php if ($isEdit): ?><p class="muted"><?= e($userCount) ?> user(s) have this role. Changes apply to them immediately.</p><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/partials/flash.php'; ?>

<?php if ($isSuper): ?>
    <div class="alert alert-info">Admin Head always has full access to every module. This role cannot be changed, so the system can never be locked out.</div>
<?php endif; ?>

<form method="post" action="<?= e(url($isEdit ? "access/roles/{$role['id']}" : 'access/roles')) ?>" class="form" novalidate>
    <?= Csrf::field() ?>
    <fieldset class="card form-grid"<?= $isSuper ? ' disabled' : '' ?>>
        <label class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <span>Role name *</span>
            <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="60" required<?= $isSystem ? ' readonly' : '' ?>>
            <?php if ($isSystem): ?><span class="muted small">Built-in role names cannot be changed.</span><?php endif; ?>
            <?= $err('name') ?>
        </label>
        <label class="field">
            <span>Description</span>
            <input type="text" name="description" value="<?= e($values['description'] ?? '') ?>" maxlength="255">
            <?= $err('description') ?>
        </label>
        <fieldset class="field field-wide<?= isset($errors['data_scope']) ? ' has-error' : '' ?>">
            <legend>Which records can this role see?</legend>
            <div class="scope-grid">
                <?php foreach ($scopes as $key => [$label, $help]): ?>
                    <label class="scope-option">
                        <input type="radio" name="data_scope" value="<?= e($key) ?>"<?= ($values['data_scope'] ?? 'own') === $key ? ' checked' : '' ?>>
                        <span><strong><?= e($label) ?></strong><span class="muted small"><?= e($help) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?= $err('data_scope') ?>
        </fieldset>
    </fieldset>

    <section class="card table-card">
        <div class="matrix-head">
            <h2>Permissions</h2>
            <?php if (!$isSuper): ?>
                <div class="matrix-tools">
                    <button type="button" class="btn btn-sm" data-matrix="view">Tick all "View"</button>
                    <button type="button" class="btn btn-sm" data-matrix="none">Clear all</button>
                </div>
            <?php endif; ?>
        </div>
        <?= $err('permissions') ?>
        <div class="table-scroll">
        <table class="table matrix" data-matrix-table>
            <thead>
                <tr>
                    <th>Module</th>
                    <?php foreach ($columns as $col): ?><th class="center"><?= e(ucfirst($col)) ?></th><?php endforeach; ?>
                    <th class="center">Row</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($catalogue as $module => $group): ?>
                <tr>
                    <th scope="row"><?= e($group['label']) ?></th>
                    <?php foreach ($columns as $col): ?>
                        <td class="center">
                            <?php if (isset($group['items'][$col])):
                                $perm = $group['items'][$col];
                                $isChecked = isset($checked[$perm['slug']]);
                                $locked = $isSuper || !isset($actorHolds[$perm['slug']]);
                            ?>
                                <label class="matrix-cell<?= $perm['critical'] ? ' critical' : '' ?>" title="<?= e($perm['slug']) ?><?= $perm['critical'] ? ' (critical)' : '' ?>">
                                    <input type="checkbox" name="permissions[]" value="<?= e($perm['id']) ?>" data-action="<?= e($col) ?>"
                                        <?= $isChecked ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                                    <span class="sr-only"><?= e($group['label'] . ' ' . $col) ?></span>
                                </label>
                                <?php if ($locked && $isChecked && !$isSuper): ?>
                                    <input type="hidden" name="permissions[]" value="<?= e($perm['id']) ?>">
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">·</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="center">
                        <?php if (!$isSuper): ?><button type="button" class="btn-mini" data-row-toggle aria-label="Toggle all <?= e($group['label']) ?>">All</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="muted small padded"><span class="critical-dot"></span> Critical permission (deleting records, users, access, settings, audit). Give it only to trusted roles.</p>
    </section>

    <?php if (!$isSuper): ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save role' : 'Create role' ?></button>
            <a class="btn" href="<?= e(url('access/roles')) ?>">Cancel</a>
        </div>
    <?php endif; ?>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/app.php';
