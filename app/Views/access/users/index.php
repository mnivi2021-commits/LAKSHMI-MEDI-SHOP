<?php
/** @var list<array<string, mixed>> $users */
/** @var list<array<string, mixed>> $roles */
/** @var array{q: string, role: int, status: string} $filters */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Csrf;
use App\Core\Gate;

$activeTab = 'users';
$scopeLabels = ['all' => 'All branches', 'branch' => 'Assigned branches', 'team' => 'Own team', 'own' => 'Own records'];
$canAdd = Gate::allows('users.add');
$canEdit = Gate::allows('users.edit');
$canDelete = Gate::allows('users.delete');
$canPerms = Gate::allows('access.manage');
$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== '' && $v !== 0));
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Access</p>
        <h1>Users</h1>
    </div>
    <?php if ($canAdd): ?>
        <a class="btn btn-primary" href="<?= e(url('access/users/new')) ?>">Add user</a>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/_tabs.php'; ?>
<?php require dirname(__DIR__, 2) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('access/users')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, username, email, mobile">
    </label>
    <label class="field">
        <span>Role</span>
        <select name="role">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= e($r['id']) ?>"<?= (int) $r['id'] === $filters['role'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Status</span>
        <select name="status">
            <option value="">Any</option>
            <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>>Disabled</option>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('access/users')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> user<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($users === []): ?>
        <p class="empty">No users match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead>
            <tr><th>User</th><th>Role</th><th>Sees</th><th>Employee</th><th>Status</th><th>Last sign-in</th><th class="right">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td>
                    <strong><?= e($u['name']) ?></strong>
                    <div class="muted small"><?= e($u['username']) ?> · <?= e($u['email']) ?></div>
                </td>
                <td><?= e($u['role_name']) ?></td>
                <td class="small">
                    <?= e($scopeLabels[$u['data_scope']] ?? $u['data_scope']) ?>
                    <?php if ($u['data_scope'] === 'branch'): ?><div class="muted"><?= e($u['branch_codes'] ?: 'none assigned') ?></div><?php endif; ?>
                </td>
                <td class="small"><?= $u['employee_name'] ? e($u['employee_name']) . ' <span class="muted">(' . e($u['employee_code']) . ')</span>' : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($u['status'] === 'active'): ?>
                        <span class="badge badge-ok">Active</span>
                    <?php else: ?>
                        <span class="badge badge-fail">Disabled</span>
                    <?php endif; ?>
                    <?php if ((int) $u['is_locked'] === 1): ?><span class="badge badge-warn">Locked</span><?php endif; ?>
                    <?php if ((int) $u['must_change_password'] === 1): ?><span class="badge badge-muted">Temp password</span><?php endif; ?>
                </td>
                <td class="small muted"><?= $u['last_login_at'] ? e(date('d-m-Y H:i', strtotime((string) $u['last_login_at']))) : 'Never' ?></td>
                <td class="right">
                    <?php if ($u['manageable']): ?>
                    <details class="row-menu">
                        <summary class="btn btn-sm">Actions</summary>
                        <div class="row-menu-panel">
                            <?php if ($canEdit): ?>
                                <a href="<?= e(url("access/users/{$u['id']}/edit")) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canPerms && $u['role_slug'] !== Gate::SUPER_ROLE): ?>
                                <a href="<?= e(url("access/users/{$u['id']}/permissions")) ?>">Permission overrides</a>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <form method="post" action="<?= e(url("access/users/{$u['id']}/reset-password")) ?>" data-confirm="Reset the password for <?= e($u['username']) ?>? They will be signed out everywhere.">
                                    <?= Csrf::field() ?><button type="submit">Reset password</button>
                                </form>
                                <?php if ((int) $u['is_locked'] === 1): ?>
                                    <form method="post" action="<?= e(url("access/users/{$u['id']}/unlock")) ?>">
                                        <?= Csrf::field() ?><button type="submit">Unlock</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url("access/users/{$u['id']}/status")) ?>"
                                      data-confirm="<?= $u['status'] === 'active' ? 'Disable ' . e($u['username']) . '? They will be signed out immediately.' : 'Enable ' . e($u['username']) . '?' ?>">
                                    <?= Csrf::field() ?><button type="submit"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url("access/users/{$u['id']}/delete")) ?>" data-confirm="Delete <?= e($u['username']) ?>? This cannot be undone from the screen.">
                                    <?= Csrf::field() ?><button type="submit" class="danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php else: ?>
                        <span class="muted small" title="This user has more access than you">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/app.php';
