<?php
/**
 * Section C - CUSTOMER drill-down. Shown when the Customer filter box has a selection.
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<string, mixed>|null $customer  DashboardController::customerPanel()
 */
use App\Core\Gate;

if ($customer === null) {
    return;
}
$cu = $customer['customer']; $s = $customer['sales']; $c = $customer['collection']; $p = $customer['pending']; $sd = $customer['sampleDc']; $o = $customer['outstanding']; $l = $customer['leads'];
$withCust = static fn (string $path, array $extra = []) => url($path) . '?' . $customer['query'] . ($extra ? '&' . http_build_query($extra) : '');
?>
<section aria-labelledby="sec-c-cust" id="customer">
    <h2 id="sec-c-cust" class="section-title">Customer: <?= e($cu['name']) ?></h2>
    <div class="card rep-panel">
        <header class="rep-head">
            <div>
                <p class="eyebrow">Selected customer</p>
                <h3 class="rep-name"><?= e($cu['name']) ?></h3>
                <p class="muted small"><?= e($cu['customer_code']) ?><?= $cu['company_name'] ? ' · ' . e($cu['company_name']) : '' ?> ·
                    <?= e($cu['branch']) ?> (<?= e($cu['branch_code']) ?>)<?= $cu['employee'] ? ' · ' . e($cu['employee']) : '' ?>
                    <?= $cu['mobile'] ? ' · ' . e($cu['mobile']) : '' ?>
                    <?php if ($cu['status'] !== 'active'): ?> · <span class="badge badge-muted"><?= e(ucfirst($cu['status'])) ?></span><?php endif; ?></p>
            </div>
            <a class="btn btn-sm" href="<?= e(url('/') . '?' . $ctx->query(['customer' => null])) ?>">Clear selection</a>
        </header>

        <div class="rep-grid">
            <section class="rep-box">
                <h4><?= Gate::allows('sales.view') ? '<a href="' . e($withCust('dashboard/sales')) . '">Sales</a>' : 'Sales' ?></h4>
                <dl>
                    <div><dt>This month</dt><dd><?= e(rupees($s['sales_month_to_previous_day'] + $s['sales_today'])) ?></dd></div>
                    <div><dt>Year to date</dt><dd><?= e(rupees($s['sales_total'])) ?></dd></div>
                    <div><dt>Last order</dt><dd><?= $customer['last_order'] ? e(date('d-m-Y', strtotime($customer['last_order']))) : '—' ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4><?= Gate::allows('collections.view') ? '<a href="' . e($withCust('dashboard/collection')) . '">Collection</a>' : 'Collection' ?></h4>
                <dl>
                    <div><dt>This month</dt><dd><?= e(rupees($c['total'])) ?></dd></div>
                    <div><dt>Year to date</dt><dd><?= e(rupees($c['fy_to_date'])) ?></dd></div>
                    <div><dt>Last payment</dt><dd><?= $customer['last_payment'] ? e(date('d-m-Y', strtotime($customer['last_payment']))) : '—' ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4><?= Gate::allows('pending_orders.view') ? '<a href="' . e($withCust('dashboard/pending')) . '">Pending order</a>' : 'Pending order' ?></h4>
                <dl>
                    <div><dt>Pending value</dt><dd><?= e(rupees($p['value'])) ?></dd></div>
                    <div><dt>Orders</dt><dd><?= e($p['orders']) ?></dd></div>
                    <div><dt>Oldest</dt><dd><?= $p['oldest'] ? e($p['oldest']['age']) . ' days' : '—' ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4>Sample / DC supply</h4>
                <dl>
                    <div><dt>Pending samples</dt><dd><?= e($sd['samples']['documents']) ?></dd></div>
                    <div><dt>Sample value</dt><dd><?= e(rupees($sd['samples']['value'])) ?></dd></div>
                    <div><dt>Pending DC</dt><dd><?= e($sd['dc']['documents']) ?></dd></div>
                    <div><dt>DC value</dt><dd><?= e(rupees($sd['dc']['value'])) ?></dd></div>
                </dl>
            </section>
        </div>

        <section class="rep-overdue">
            <h4>Overdue payment</h4>
            <div class="overdue-grid">
                <?php foreach ($o as $key => $cat): ?>
                    <a class="overdue-box overdue-<?= e($key) ?>" href="<?= e($withCust('dashboard/outstanding', ['cat' => $key])) ?>">
                        <span class="overdue-label"><?= e($cat['label']) ?></span>
                        <strong><?= e(rupees($cat['value'])) ?></strong>
                        <span class="small"><?= e($cat['bills']) ?> bill(s)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <p class="rep-leads small">
            <strong><?= e($l['open_leads']) ?></strong> open lead(s) ·
            <strong class="<?= $l['followups_due'] > 0 ? 'bad' : '' ?>"><?= e($l['followups_due']) ?></strong> follow-up(s) due
        </p>
    </div>
</section>
