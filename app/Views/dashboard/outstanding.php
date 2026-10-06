<?php
/**
 * 90 / 150 Day Outstanding drill-down.
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<string, array{label: string, short: string, value: int, customers: int, bills: int}> $categories
 * @var int $total
 * @var bool $applies
 * @var string|null $cat
 * @var list<array<string, mixed>> $customers
 * @var array{rows: list<array<string, mixed>>, total: int, count: int, customers: int} $bills
 * @var int $page
 * @var int $pages
 * @var bool $canExport
 * @var string $billSource
 * @var string $ageBasis
 */
use App\Core\Money;

$back = url('/') . '?' . $ctx->query();
$self = static fn (array $o): string => url('dashboard/outstanding') . '?' . $ctx->query($o);
$expected = $cat === null ? $total : ($categories[$cat]['value'] ?? 0);
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e($back) ?>">Dashboard</a> / Outstanding</p>
        <h1>90 / 150 Day Outstanding</h1>
        <p class="muted asof">As on <strong><?= e($ctx->asOn->format('d-m-Y')) ?></strong> ·
            <?= $billSource === 'imported' ? 'from the latest uploaded accounting statement' : 'invoices − adjusted receipts − credit notes' ?> ·
            age = <?= e($ageBasis) ?></p>
    </div>
    <a class="btn" href="<?= e($back) ?>">← Back to dashboard</a>
</div>
<?php foreach ($ctx->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<?php if (!$applies): ?>
    <div class="card"><p>Outstanding is tracked per customer bill, not per product, so this view does not apply to a product filter.</p></div>
<?php else: ?>

<section class="stat-grid" aria-label="Summary">
    <div class="card stat"><span>Total outstanding</span><strong><?= e(rupees($total)) ?></strong><small class="muted"><?= e(array_sum(array_column($categories, 'bills'))) ?> bill(s)</small></div>
    <?php foreach ($categories as $key => $c): ?>
        <div class="card stat"><span><?= e($c['label']) ?></span><strong<?= $key !== 'upto90' ? ' class="bad"' : '' ?>><?= e(rupees($c['value'])) ?></strong>
            <small class="muted"><?= e($c['customers']) ?> customer(s) · <?= e($c['bills']) ?> bill(s)</small></div>
    <?php endforeach; ?>
</section>

<section class="card table-card">
    <h2>By age category</h2>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Category</th><th class="right">Outstanding</th><th class="right">Bills</th><th class="right">Customers</th><th class="right">Share</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $key => $c): ?>
            <tr class="<?= $cat === $key ? 'selected' : '' ?>">
                <td><a href="<?= e($self(['cat' => $key]) . '#bills') ?>"><?= e($c['label']) ?></a></td>
                <td class="right num"><?= e(rupees($c['value'])) ?></td><td class="right num"><?= e($c['bills']) ?></td><td class="right num"><?= e($c['customers']) ?></td>
                <td class="right num"><?= $total ? e(number_format($c['value'] * 100 / $total, 1)) . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="card table-card">
    <h2>By customer<?= $cat ? ' · ' . e($categories[$cat]['label']) : '' ?></h2>
    <?php if ($customers === []): ?><p class="empty">No outstanding bills in this view.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Customer</th><th>Branch</th><th>Employee</th><th class="right">Bills</th><th class="right">Oldest (days)</th><th>Earliest due</th><th class="right">Outstanding</th></tr></thead>
        <tbody>
        <?php foreach ($customers as $r): ?>
            <tr><td><?= e($r['customer']) ?> <span class="muted small"><?= e($r['customer_code']) ?><?= $r['mobile'] ? ' · ' . e($r['mobile']) : '' ?></span></td>
                <td><?= e($r['branch_code']) ?></td><td><?= e($r['employee'] ?? '—') ?></td><td class="right num"><?= e($r['bills']) ?></td>
                <td class="right num<?= (int) $r['oldest_age'] > 90 ? ' neg' : '' ?>"><?= e($r['oldest_age']) ?></td>
                <td class="nowrap"><?= e(date('d-m-Y', strtotime($r['earliest_due']))) ?></td>
                <td class="right num"><?= e(rupees($r['value'], 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card table-card" id="bills">
    <div class="matrix-head">
        <h2>Bills<?= $cat ? ' · ' . e($categories[$cat]['label']) : '' ?></h2>
        <div class="matrix-tools">
            <?php if ($cat): ?><a class="btn btn-sm" href="<?= e($self([]) . '#bills') ?>">Show all categories</a><?php endif; ?>
            <?php if ($canExport): ?><a class="btn btn-sm" href="<?= e(url('dashboard/outstanding/export') . '?' . $ctx->query(['cat' => $cat])) ?>">Export CSV</a><?php endif; ?>
        </div>
    </div>
    <p class="padded small"><?= e($bills['count']) ?> bill(s) · <?= e($bills['customers']) ?> customer(s) · outstanding <strong><?= e(rupees($bills['total'], 2)) ?></strong>
        <?php if ($bills['total'] === $expected): ?><span class="badge badge-ok">matches dashboard</span><?php else: ?><span class="badge badge-fail">does not match dashboard</span><?php endif; ?></p>
    <?php if ($bills['rows'] === []): ?><p class="empty">No outstanding bills here.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Invoice</th><th>Invoice date</th><th>Due date</th><th class="right">Age</th><th>Customer</th><th>Branch</th><th>Employee</th><th class="right">Bill amount</th><th class="right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($bills['rows'] as $r): ?>
            <tr><td class="nowrap"><?= e($r['invoice_no']) ?></td><td class="nowrap"><?= e(date('d-m-Y', strtotime($r['invoice_date']))) ?></td>
                <td class="nowrap"><?= e(date('d-m-Y', strtotime($r['due_date']))) ?></td><td class="right num<?= (int) $r['age'] > 90 ? ' neg' : '' ?>"><?= e($r['age']) ?></td>
                <td><?= e($r['customer']) ?> <span class="muted small"><?= e($r['customer_code']) ?></span></td><td><?= e($r['branch_code']) ?></td><td><?= e($r['employee'] ?? '—') ?></td>
                <td class="right num"><?= e(rupees(Money::fromDb($r['bill_amount']), 2)) ?></td>
                <td class="right num"><?= e(rupees(Money::fromDb($r['balance']), 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
        <nav class="pagination"><?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($self(['cat' => $cat, 'page' => $page - 1]) . '#bills') ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($self(['cat' => $cat, 'page' => $page + 1]) . '#bills') ?>">Next</a><?php endif; ?></nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
