<?php
/** @var string $type */
/** @var array{label: string, singular: string, fk: string} $def */
/** @var list<array<string, mixed>> $rows */
use App\Core\Csrf;

ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('hrm')) ?>">HRM</a></p>
        <h1><?= e($def['label']) ?></h1>
    </div>
    <div class="form-actions">
        <a class="btn<?= $type === 'departments' ? ' btn-primary' : '' ?>" href="<?= e(url('hrm/lists/departments')) ?>">Departments</a>
        <a class="btn<?= $type === 'designations' ? ' btn-primary' : '' ?>" href="<?= e(url('hrm/lists/designations')) ?>">Designations</a>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<?php if ($canEdit): ?>
<form method="post" action="<?= e(url("hrm/lists/{$type}")) ?>" class="filters card">
    <?= Csrf::field() ?>
    <label class="field">
        <span>New <?= e(strtolower($def['singular'])) ?></span>
        <input type="text" name="name" maxlength="80" required>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Add</button>
    </div>
</form>
<?php endif; ?>

<section class="card table-card">
    <h2><?= e(count($rows)) ?> <?= e(strtolower($def['label'])) ?></h2>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Name</th><th class="right">Employees</th><th>Status</th><?php if ($canEdit): ?><th class="right">Actions</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <?php if ($canEdit): ?>
                        <form method="post" action="<?= e(url("hrm/lists/{$type}/{$r['id']}")) ?>" class="inline-rename">
                            <?= Csrf::field() ?>
                            <input type="text" name="name" value="<?= e($r['name']) ?>" maxlength="80" aria-label="Name" required>
                            <button type="submit" class="btn btn-sm">Rename</button>
                        </form>
                    <?php else: ?>
                        <?= e($r['name']) ?>
                    <?php endif; ?>
                </td>
                <td class="right num"><a href="<?= e(url('hrm') . ($type === 'departments' ? '?department=' . $r['id'] : '')) ?>"><?= e($r['employees']) ?></a></td>
                <td><span class="badge <?= $r['status'] === 'active' ? 'badge-ok' : 'badge-muted' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                <?php if ($canEdit): ?>
                <td class="right">
                    <form method="post" action="<?= e(url("hrm/lists/{$type}/{$r['id']}")) ?>">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="toggle">
                        <button type="submit" class="btn btn-sm"><?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
