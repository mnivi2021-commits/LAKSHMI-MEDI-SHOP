<?php
/** @var list<array<string, mixed>> $roles */
/** @var int $totalPerms */
use App\Core\Csrf;
use App\Core\Gate;

$activeTab = 'roles';
$scopeLabels = ['all' => 'All branches', 'branch' => 'Assigned branches', 'team' => 'Own team', 'own' => 'Own records'];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Access</p>
        <h1>Roles &amp; permissions</h1>
    </div>
    <a class="btn btn-primary" href="<?= e(url('access/roles/new')) ?>">New role</a>
</div>

<?php require dirname(__DIR__) . '/_tabs.php'; ?>
<?php require dirname(__DIR__, 2) . '/partials/flash.php'; ?>

<section class="card table-card">
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Role</th><th>Sees</th><th>Permissions</th><th>Users</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
            <tr>
                <td>
                    <strong><?= e($r['name']) ?></strong>
                    <?php if ((int) $r['is_system'] === 1): ?><span class="badge badge-muted">built-in</span><?php endif; ?>
                    <div class="muted small"><?= e($r['description'] ?? '') ?></div>
                </td>
                <td class="small"><?= e($scopeLabels[$r['data_scope']] ?? $r['data_scope']) ?></td>
                <td>
                    <div class="meter" aria-label="<?= e($r['permission_count']) ?> of <?= e($totalPerms) ?>">
                        <span class="meter-fill" data-width="<?= e(round(100 * (int) $r['permission_count'] / max(1, $totalPerms))) ?>"></span>
                    </div>
                    <span class="small muted"><?= e($r['permission_count']) ?> / <?= e($totalPerms) ?></span>
                </td>
                <td><?= e($r['user_count']) ?></td>
                <td class="right nowrap">
                    <a class="btn btn-sm" href="<?= e(url("access/roles/{$r['id']}")) ?>"><?= $r['slug'] === Gate::SUPER_ROLE ? 'View' : 'Edit' ?></a>
                    <?php if ((int) $r['is_system'] === 0): ?>
                        <form method="post" action="<?= e(url("access/roles/{$r['id']}/delete")) ?>" class="inline-form" data-confirm="Delete role <?= e($r['name']) ?>?">
                            <?= Csrf::field() ?><button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/app.php';
