<?php
/**
 * Section C - PRODUCT drill-down. Shown when the Product filter box has a selection.
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<string, mixed>|null $product  DashboardController::productPanel()
 */
use App\Core\Gate;

if ($product === null) {
    return;
}
$pr = $product['product']; $s = $product['sales']; $p = $product['pending']; $sd = $product['sampleDc'];
$withProd = static fn (string $path, array $extra = []) => url($path) . '?' . $product['query'] . ($extra ? '&' . http_build_query($extra) : '');
?>
<section aria-labelledby="sec-c-prod" id="product">
    <h2 id="sec-c-prod" class="section-title">Product: <?= e($pr['name']) ?></h2>
    <div class="card rep-panel">
        <header class="rep-head">
            <div>
                <p class="eyebrow">Selected product</p>
                <h3 class="rep-name"><?= e($pr['name']) ?></h3>
                <p class="muted small"><?= e($pr['product_code']) ?><?= $pr['category'] ? ' · ' . e($pr['category']) : '' ?> ·
                    <?= e(rupees((int) round($pr['rate'] * 100))) ?> / <?= e($pr['unit']) ?> · GST <?= e(rtrim(rtrim((string) $pr['gst_rate'], '0'), '.')) ?>%
                    <?php if ($pr['status'] !== 'active'): ?> · <span class="badge badge-muted"><?= e(ucfirst($pr['status'])) ?></span><?php endif; ?></p>
            </div>
            <a class="btn btn-sm" href="<?= e(url('/') . '?' . $ctx->query(['product' => null])) ?>">Clear selection</a>
        </header>

        <div class="rep-grid">
            <section class="rep-box">
                <h4><?= Gate::allows('sales.view') ? '<a href="' . e($withProd('dashboard/sales')) . '">Sales</a>' : 'Sales' ?></h4>
                <dl>
                    <div><dt>Year to date</dt><dd><?= e(rupees($s['sales_total'])) ?></dd></div>
                    <div><dt>Quantity sold</dt><dd><?= e($product['quantity_sold']) ?> <?= e($pr['unit']) ?></dd></div>
                    <div><dt>Customers</dt><dd><?= e($product['customers']) ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4><?= Gate::allows('pending_orders.view') ? '<a href="' . e($withProd('dashboard/pending')) . '">Pending order</a>' : 'Pending order' ?></h4>
                <dl>
                    <div><dt>Pending value</dt><dd><?= e(rupees($p['value'])) ?></dd></div>
                    <div><dt>Orders</dt><dd><?= e($p['orders']) ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4>Sample / DC supply</h4>
                <dl>
                    <div><dt>Pending samples</dt><dd><?= e($sd['samples']['documents']) ?> · <?= e(rupees($sd['samples']['value'])) ?></dd></div>
                    <div><dt>Pending DC</dt><dd><?= e($sd['dc']['documents']) ?> · <?= e(rupees($sd['dc']['value'])) ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4>Collection / Outstanding</h4>
                <p class="muted small">Payments are received per invoice, not per product line, so these do not apply here. See Sales for this product's value, or open a customer to see their payment status.</p>
            </section>
        </div>

        <?php if ($product['byEmployee'] !== []): ?>
        <section class="rep-overdue">
            <h4>By sales employee</h4>
            <div class="table-scroll"><table class="table compact">
                <thead><tr><th>Employee</th><th class="right">Year to date</th></tr></thead>
                <tbody>
                <?php foreach ($product['byEmployee'] as $r): ?>
                    <tr><td><?= e($r['label']) ?></td><td class="right num"><?= e(rupees($r['fy_to_date'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
        <?php endif; ?>
    </div>
</section>
