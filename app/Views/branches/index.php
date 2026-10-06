<?php
/** @var list<array<string, mixed>> $branches */
/** @var array{q: string, status: string} $filters */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Csrf;

$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== ''));
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Branch Details</p>
        <h1>Branches</h1>
    </div>
    <div class="form-actions">
        <?php if ($canExport): ?><a class="btn" href="<?= e(url('branches/export')) ?>">Export CSV</a><?php endif; ?>
        <?php if ($canAdd): ?><a class="btn btn-primary" href="<?= e(url('branches/new')) ?>">Add branch</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('branches')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, code, city, state">
    </label>
    <label class="field">
        <span>Status</span>
        <select name="status">
            <option value="">Any</option>
            <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('branches')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> branch<?= $total === 1 ? '' : 'es' ?></h2>
    <?php if ($branches === []): ?>
        <p class="empty">No branches match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Branch</th><th>Location</th><th>Contact</th><th>Manager</th><th class="right">Employees</th><th class="right">Customers</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($branches as $b): ?>
            <tr>
                <td><strong><?= e($b['name']) ?></strong><div class="muted small"><?= e($b['branch_code']) ?></div></td>
                <td class="small"><?= e(trim(($b['city'] ?? '') . ($b['state'] ? ', ' . $b['state'] : ''), ', ') ?: '—') ?><?= $b['pincode'] ? '<div class="muted">' . e($b['pincode']) . '</div>' : '' ?></td>
                <td class="small"><?= e($b['contact_number'] ?? '—') ?><?= $b['email'] ? '<div class="muted">' . e($b['email']) . '</div>' : '' ?></td>
                <td class="small"><?= e($b['manager_name'] ?? '—') ?></td>
                <td class="right num"><?= e($b['employee_count']) ?></td>
                <td class="right num"><?= e($b['customer_count']) ?></td>
                <td><?= $b['status'] === 'active' ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-fail">Inactive</span>' ?></td>
                <td class="right">
                    <details class="row-menu">
                        <summary class="btn btn-sm">Actions</summary>
                        <div class="row-menu-panel">
                            <a href="<?= e(url("branches/{$b['id']}/edit")) ?>"><?= $canEdit ? 'Edit' : 'View' ?></a>
                            <?php if ($canEdit): ?>
                                <form method="post" action="<?= e(url("branches/{$b['id']}/status")) ?>"
                                      data-confirm="<?= $b['status'] === 'active' ? 'Disable ' . e($b['name']) . '?' : 'Enable ' . e($b['name']) . '?' ?>">
                                    <?= Csrf::field() ?><button type="submit"><?= $b['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url("branches/{$b['id']}/delete")) ?>" data-confirm="Delete <?= e($b['name']) ?>?">
                                    <?= Csrf::field() ?><button type="submit" class="danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
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
require dirname(__DIR__) . '/layouts/app.php';
