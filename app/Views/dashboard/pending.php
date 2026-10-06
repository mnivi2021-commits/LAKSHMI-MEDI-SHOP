<?php
/**
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<string, mixed> $p
 * @var list<array<string, mixed>> $byBranch
 * @var list<array<string, mixed>> $byEmployee
 * @var string|null $bucket
 * @var array{rows: list<array<string, mixed>>, total: int, count: int, orders: int} $lines
 * @var int $page
 * @var int $pages
 * @var bool $canExport
 */
use App\Core\Money;

$back = url('/') . '?' . $ctx->query();
$self = static fn (array $o): string => url('dashboard/pending') . '?' . $ctx->query($o);
$expected = $bucket === null ? $p['value'] : (array_column($p['aging'], 'value', 'key')[$bucket] ?? 0);
$fmtQty = static fn ($q): string => rtrim(rtrim((string) $q, '0'), '.');
// Buckets are returned in the same order as Aging::buckets(); sum those that start after 90 days.
$over90 = 0;
foreach (\App\Modules\Dashboard\Kpi\Aging::buckets() as $i => $bk) {
    if ($bk['min'] > 90) {
        $over90 += $p['aging'][$i]['value'];
    }
}
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e($back) ?>">Dashboard</a> / Step A3</p>
        <h1>Branch Pending Order</h1>
        <p class="muted asof">As on <strong><?= e($ctx->asOn->format('d-m-Y')) ?></strong> · open &amp; partly supplied orders · pending = order value − supplied value</p>
    </div>
    <a class="btn" href="<?= e($back) ?>">← Back to dashboard</a>
</div>
<?php foreach ($ctx->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<section class="stat-grid" aria-label="Summary">
    <div class="card stat"><span>Total pending value</span><strong><?= e(rupees($p['value'])) ?></strong><small class="muted"><?= e($p['orders']) ?> orders · <?= e($p['customers']) ?> customers</small></div>
    <div class="card stat"><span>Current pending (<?= e($p['month_name']) ?>)</span><strong><?= e(rupees($p['current_value'])) ?></strong><small class="muted"><?= e($p['current_orders']) ?> order(s) placed this month</small></div>
    <div class="card stat"><span>Oldest pending order</span><strong><?= $p['oldest'] ? e($p['oldest']['age']) . ' days' : '—' ?></strong>
        <small class="muted"><?= $p['oldest'] ? e($p['oldest']['order_no'] . ' · ' . date('d-m-Y', strtotime($p['oldest']['order_date'])) . ' · ' . $p['oldest']['customer'] . ' · ' . rupees($p['oldest']['value'])) : '' ?></small></div>
    <div class="card stat"><span>Older than 90 days</span><strong class="bad"><?= e(rupees($over90)) ?></strong><small class="muted">orders placed more than 90 days ago</small></div>
</section>

<section class="card table-card">
    <h2>Aging</h2>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Age</th><th class="right">Pending value</th><th class="right">Orders</th><th class="right">Customers</th><th class="right">Share</th></tr></thead>
        <tbody>
        <?php foreach ($p['aging'] as $b): ?>
            <tr class="<?= $bucket === $b['key'] ? 'selected' : '' ?>">
                <td><a href="<?= e($self(['bucket' => $b['key']]) . '#lines') ?>"><?= e($b['label']) ?></a></td>
                <td class="right num"><?= e(rupees($b['value'])) ?></td><td class="right num"><?= e($b['orders']) ?></td><td class="right num"><?= e($b['customers']) ?></td>
                <td class="right num"><?= $p['value'] ? e(number_format($b['value'] * 100 / $p['value'], 1)) . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<div class="split">
    <?php foreach (['Branch' => $byBranch, 'Sales employee' => $byEmployee] as $title => $rows): ?>
    <section class="card table-card">
        <h2>By <?= e(strtolower($title)) ?></h2>
        <?php if ($rows === []): ?><p class="empty">No pending orders in this view.</p><?php else: ?>
        <div class="table-scroll"><table class="table compact">
            <thead><tr><th><?= e($title) ?></th><th class="right">Pending value</th><th class="right">Orders</th><th class="right">Customers</th><th class="right">Over 90 days</th><th>Oldest</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['label']) ?></td><td class="right num"><?= e(rupees($r['value'])) ?></td><td class="right num"><?= e($r['orders']) ?></td>
                    <td class="right num"><?= e($r['customers']) ?></td><td class="right num<?= $r['over_90'] > 0 ? ' neg' : '' ?>"><?= e(rupees($r['over_90'])) ?></td>
                    <td class="nowrap"><?= e(date('d-m-Y', strtotime($r['oldest']))) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<section class="card table-card" id="lines">
    <div class="matrix-head">
        <h2>Pending order lines<?= $bucket ? ' · ' . e(\App\Modules\Dashboard\Kpi\Aging::find($bucket) ? str_replace('-', '–', $bucket) . ' days' : '') : '' ?></h2>
        <div class="matrix-tools">
            <?php if ($bucket): ?><a class="btn btn-sm" href="<?= e($self([]) . '#lines') ?>">Show all ages</a><?php endif; ?>
            <?php if ($canExport): ?><a class="btn btn-sm" href="<?= e(url('dashboard/pending/export') . '?' . $ctx->query(['bucket' => $bucket])) ?>">Export CSV</a><?php endif; ?>
        </div>
    </div>
    <p class="padded small"><?= e($lines['orders']) ?> order(s) · <?= e($lines['count']) ?> line(s) · pending <strong><?= e(rupees($lines['total'], 2)) ?></strong>
        <?php if ($lines['total'] === $expected): ?><span class="badge badge-ok">matches dashboard</span><?php else: ?><span class="badge badge-fail">does not match dashboard</span><?php endif; ?></p>
    <?php if ($lines['rows'] === []): ?><p class="empty">No pending orders here.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Order</th><th>Date</th><th class="right">Age</th><th>Customer</th><th>Branch</th><th>Employee</th><th>Product</th><th class="right">Ordered</th><th class="right">Supplied</th><th class="right">Pending qty</th><th class="right">Pending value</th><th>Expected</th></tr></thead>
        <tbody>
        <?php foreach ($lines['rows'] as $r): $late = $r['expected_delivery_date'] && $r['expected_delivery_date'] < $ctx->asOn->format('Y-m-d'); ?>
            <tr><td class="nowrap"><?= e($r['order_no']) ?><?= $r['status'] === 'partial' ? ' <span class="badge badge-muted">partial</span>' : '' ?></td>
                <td class="nowrap"><?= e(date('d-m-Y', strtotime($r['order_date']))) ?></td><td class="right num<?= (int) $r['age'] > 90 ? ' neg' : '' ?>"><?= e($r['age']) ?></td>
                <td><?= e($r['customer']) ?> <span class="muted small"><?= e($r['customer_code']) ?></span></td><td><?= e($r['branch_code']) ?></td><td><?= e($r['employee'] ?? '—') ?></td>
                <td><?= e($r['product']) ?></td><td class="right num"><?= e($fmtQty($r['order_qty'])) ?></td><td class="right num"><?= e($fmtQty($r['supplied_qty'])) ?></td>
                <td class="right num"><?= e($fmtQty($r['pending_qty'])) ?> <span class="muted small"><?= e($r['unit']) ?></span></td>
                <td class="right num"><?= e(rupees(Money::fromDb($r['pending_value']), 2)) ?></td>
                <td class="nowrap<?= $late ? ' neg' : '' ?>"><?= $r['expected_delivery_date'] ? e(date('d-m-Y', strtotime($r['expected_delivery_date']))) . ($late ? ' (late)' : '') : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
        <nav class="pagination"><?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($self(['bucket' => $bucket, 'page' => $page - 1]) . '#lines') ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($self(['bucket' => $bucket, 'page' => $page + 1]) . '#lines') ?>">Next</a><?php endif; ?></nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
