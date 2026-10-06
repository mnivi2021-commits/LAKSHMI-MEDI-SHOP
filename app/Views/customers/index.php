<?php
/** @var list<array<string, mixed>> $customers */
/** @var array{q: string, status: string, branch: ?int} $filters */
/** @var list<array<string, mixed>> $branches */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Csrf;

$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== '' && $v !== null));
$statusBadge = ['active' => 'badge-ok', 'inactive' => 'badge-muted', 'blocked' => 'badge-fail'];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Customers</p>
        <h1>Customers</h1>
    </div>
    <div class="form-actions">
        <?php if ($canExport): ?><a class="btn" href="<?= e(url('customers/export')) ?>">Export CSV</a><?php endif; ?>
        <?php if ($canAdd): ?><a class="btn btn-primary" href="<?= e(url('customers/new')) ?>">Add customer</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('customers')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, code, company, mobile, GSTIN">
    </label>
    <label class="field">
        <span>Branch</span>
        <select name="branch">
            <option value="">All branches</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === $filters['branch'] ? ' selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['branch_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Status</span>
        <select name="status">
            <option value="">Any</option>
            <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
            <option value="blocked"<?= $filters['status'] === 'blocked' ? ' selected' : '' ?>>Blocked</option>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('customers')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> customer<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($customers === []): ?>
        <p class="empty">No customers match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Customer</th><th>Contact</th><th>Location</th><th>Branch</th><th>Employee</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <tr>
                <td><a href="<?= e(url("customers/{$c['id']}")) ?>"><strong><?= e($c['name']) ?></strong></a>
                    <div class="muted small"><?= e($c['customer_code']) ?><?= $c['company_name'] ? ' · ' . e($c['company_name']) : '' ?></div></td>
                <td class="small"><?= e($c['mobile'] ?? '—') ?><?= $c['email'] ? '<div class="muted">' . e($c['email']) . '</div>' : '' ?></td>
                <td class="small"><?= e(trim(($c['city'] ?? '') . ($c['state'] ? ', ' . $c['state'] : ''), ', ') ?: '—') ?></td>
                <td class="small"><?= e($c['branch_code']) ?></td>
                <td class="small"><?= e($c['employee_short'] ?? '—') ?></td>
                <td><span class="badge <?= e($statusBadge[$c['status']] ?? 'badge-muted') ?>"><?= e(ucfirst($c['status'])) ?></span></td>
                <td class="right">
                    <details class="row-menu">
                        <summary class="btn btn-sm">Actions</summary>
                        <div class="row-menu-panel">
                            <a href="<?= e(url("customers/{$c['id']}")) ?>">View</a>
                            <?php if ($canEdit): ?><a href="<?= e(url("customers/{$c['id']}/edit")) ?>">Edit</a><?php endif; ?>
                            <?php if ($canEdit): ?>
                                <?php foreach (['active' => 'Mark active', 'inactive' => 'Mark inactive', 'blocked' => 'Block'] as $st => $label): if ($st === $c['status']) { continue; } ?>
                                    <form method="post" action="<?= e(url("customers/{$c['id']}/status")) ?>">
                                        <?= Csrf::field() ?><input type="hidden" name="status" value="<?= e($st) ?>"><button type="submit"><?= e($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url("customers/{$c['id']}/delete")) ?>" data-confirm="Delete <?= e($c['name']) ?>?">
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
