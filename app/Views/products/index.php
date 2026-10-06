<?php
/** @var list<array<string, mixed>> $products */
/** @var array{q: string, status: string, category: string} $filters */
/** @var list<string> $categories */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Csrf;
use App\Core\Money;

$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== ''));
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Products</p>
        <h1>Products</h1>
    </div>
    <div class="form-actions">
        <?php if ($canExport): ?><a class="btn" href="<?= e(url('products/export')) ?>">Export CSV</a><?php endif; ?>
        <?php if ($canAdd): ?><a class="btn btn-primary" href="<?= e(url('products/new')) ?>">Add product</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('products')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, code, HSN">
    </label>
    <label class="field">
        <span>Category</span>
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat) ?>"<?= $cat === $filters['category'] ? ' selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
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
        <a class="btn" href="<?= e(url('products')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> product<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($products === []): ?>
        <p class="empty">No products match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Product</th><th>Category</th><th>Unit</th><th>HSN</th><th class="right">Rate</th><th class="right">GST</th><th class="right">Sales (12 months)</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><strong><?= e($p['name']) ?></strong><div class="muted small"><?= e($p['product_code']) ?></div></td>
                <td class="small"><?= e($p['category'] ?? '—') ?></td>
                <td class="small"><?= e($p['unit']) ?></td>
                <td class="small"><?= e($p['hsn_code'] ?? '—') ?></td>
                <td class="right num"><?= e(rupees(Money::fromDb($p['rate']), 2)) ?></td>
                <td class="right num"><?= e(rtrim(rtrim((string) $p['gst_rate'], '0'), '.') ?: '0') ?>%</td>
                <td class="right num"><?= e(rupees(Money::fromDb($p['sales_12m']))) ?></td>
                <td><?= $p['status'] === 'active' ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                <td class="right">
                    <?php if ($canEdit || $canDelete): ?>
                    <details class="row-menu">
                        <summary class="btn btn-sm">Actions</summary>
                        <div class="row-menu-panel">
                            <?php if ($canEdit): ?>
                                <a href="<?= e(url("products/{$p['id']}/edit")) ?>">Edit</a>
                                <form method="post" action="<?= e(url("products/{$p['id']}/status")) ?>">
                                    <?= Csrf::field() ?><button type="submit"><?= $p['status'] === 'active' ? 'Mark inactive' : 'Mark active' ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url("products/{$p['id']}/delete")) ?>" data-confirm="Delete <?= e($p['name']) ?>?">
                                    <?= Csrf::field() ?><button type="submit" class="danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php else: ?><span class="muted small">—</span><?php endif; ?>
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
