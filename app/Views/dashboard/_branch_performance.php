<?php
/** @var \App\Modules\Dashboard\DashboardContext $ctx */
/** @var array<string, mixed> $bp */
$per = $bp['periods'];
$sales = $bp['sales'];
$pos = $bp['positions']['totals'];
$leads = $bp['leads'];
$col = $bp['collection'];
$by = $sales['by'] === 'branch' ? 'Branch' : 'Sales employee';
// Every figure opens the sheet rows behind it.
$link = static fn (string $metric, array $extra = []): string => url('dashboard/entries') . '?' . $ctx->query(['metric' => $metric] + $extra);
$money = static fn (int $v): string => rupees($v);
$pct = static fn (?float $v): string => $v === null ? '—' : number_format($v, 1) . '%';
// Colour against the share of the period already gone: ahead = green, well behind (< 80% of pace) = red.
$tone = static function (?float $v, ?float $pace = null) use ($per): string {
    $pace ??= (float) $per['month_pace'];
    if ($v === null || $pace <= 0) {
        return '';
    }
    return $v >= $pace ? ' pos' : ($v < $pace * 0.8 ? ' neg' : '');
};
$cell = static fn (string $metric, int $v, array $extra = []): string => '<a href="' . e($link($metric, $extra)) . '">' . e(rupees($v)) . '</a>';
$rowFilter = static fn (array $r): array => $sales['by'] === 'branch' ? ['branch' => $r['id']] : ['employee' => $r['id']];
?>
<section aria-labelledby="bp-sales">
    <h2 id="bp-sales" class="section-title">1. Sales performance</h2>
    <div class="card table-card">
        <div class="table-scroll">
        <table class="table compact grid-table bp-table">
            <thead>
                <tr>
                    <th rowspan="2"><?= e($by) ?></th>
                    <th class="right" rowspan="2">Annual target</th>
                    <th class="right" rowspan="2">Sales as on previous day<div class="th-sub"><?= e($per['fy_prev']) ?></div></th>
                    <th class="right" rowspan="2">Month target<div class="th-sub"><?= e($per['month']) ?></div></th>
                    <th class="center bp-group" colspan="2">This month sales<div class="th-sub"><?= e($per['month_prev']) ?></div></th>
                    <th class="right" rowspan="2">Today sales<div class="th-sub"><?= e($per['today']) ?></div></th>
                </tr>
                <tr><th class="right">Value</th><th class="right">% of target</th></tr>
            </thead>
            <tbody>
            <?php if ($sales['rows'] === []): ?>
                <tr><td colspan="7" class="empty">No targets or daily entries for this selection yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($sales['rows'] as $r): $x = $rowFilter($r); ?>
                <tr>
                    <td class="nowrap"><?= e($r['label']) ?></td>
                    <td class="right num"><?= e($money($r['annual_target'])) ?></td>
                    <td class="right num"><?= $cell('sales_fy', $r['fy_prev'], $x) ?><div class="th-sub"><?= e($pct($r['fy_pct'])) ?> of annual</div></td>
                    <td class="right num"><?= e($money($r['month_target'])) ?></td>
                    <td class="right num"><?= $cell('sales_month', $r['month_prev'], $x) ?></td>
                    <td class="right num<?= $tone($r['month_pct']) ?>"><strong><?= e($pct($r['month_pct'])) ?></strong></td>
                    <td class="right num"><?= $cell('sales_today', $r['today'], $x) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (count($sales['rows']) > 1): $t = $sales['total']; ?>
            <tfoot><tr class="total-row">
                <th>Total</th>
                <th class="right num"><?= e($money($t['annual_target'])) ?></th>
                <th class="right num"><?= $cell('sales_fy', $t['fy_prev']) ?><div class="th-sub"><?= e($pct($t['fy_pct'])) ?> of annual</div></th>
                <th class="right num"><?= e($money($t['month_target'])) ?></th>
                <th class="right num"><?= $cell('sales_month', $t['month_prev']) ?></th>
                <th class="right num<?= $tone($t['month_pct']) ?>"><?= e($pct($t['month_pct'])) ?></th>
                <th class="right num"><?= $cell('sales_today', $t['today']) ?></th>
            </tr></tfoot>
            <?php endif; ?>
        </table>
        </div>
        <p class="muted small padded">% of target = this month's sales ÷ month target × 100. Green = ahead of pace (<?= e(number_format($per['month_pace'], 1)) ?>% of the month has passed), red = well behind.
            Today's sales are shown separately and are not in "previous day" figures.</p>
    </div>
</section>

<div class="bp-grid">
    <section class="card bp-card" aria-labelledby="bp-pending">
        <h2 id="bp-pending" class="bp-title">2. Pending order · Enquiry · Lead</h2>
        <h3 class="bp-sub">Pending orders <span class="muted small">as on <?= e($per['as_on']) ?></span></h3>
        <dl class="bp-list">
            <div><dt>Non stock</dt><dd><a href="<?= e($link('po_non_stock')) ?>"><?= e($money($pos['po_non_stock'])) ?></a></dd></div>
            <div><dt>Price issue</dt><dd><a href="<?= e($link('po_price_issue')) ?>"><?= e($money($pos['po_price_issue'])) ?></a></dd></div>
            <div><dt>Doubtful</dt><dd><a href="<?= e($link('po_doubt')) ?>"><?= e($money($pos['po_doubt'])) ?></a></dd></div>
            <div class="bp-total"><dt>Total pending</dt><dd><?= e($money($pos['po_non_stock'] + $pos['po_price_issue'] + $pos['po_doubt'])) ?></dd></div>
        </dl>
        <h3 class="bp-sub">Enquiry pending</h3>
        <dl class="bp-list">
            <div><dt>New customer</dt><dd><a href="<?= e($link('enq_new_customer')) ?>"><?= e($pos['enq_new_customer']) ?></a></dd></div>
            <div><dt>New product</dt><dd><a href="<?= e($link('enq_new_product')) ?>"><?= e($pos['enq_new_product']) ?></a></dd></div>
        </dl>
        <h3 class="bp-sub">Leads created <span class="muted small"><?= e($per['month']) ?></span></h3>
        <dl class="bp-list">
            <div><dt>New customer</dt><dd><a href="<?= e($link('lead_new_customer')) ?>"><?= e($leads['new_customer']) ?></a></dd></div>
            <div><dt>New product</dt><dd><a href="<?= e($link('lead_new_product')) ?>"><?= e($leads['new_product']) ?></a></dd></div>
        </dl>
    </section>

    <section class="card bp-card" aria-labelledby="bp-coll">
        <h2 id="bp-coll" class="bp-title">3. Payment collection</h2>
        <dl class="bp-list">
            <div><dt>Month opening outstanding<span class="th-sub"><?= e($per['month']) ?><?= $col['opening_reps'] === 0 ? ' · not entered' : '' ?></span></dt>
                <dd><a href="<?= e($link('opening')) ?>"><?= e($money($col['opening'])) ?></a></dd></div>
            <div><dt>Collection<span class="th-sub"><?= e($per['month_prev']) ?> · NOB <?= e($col['month_nob']) ?> · NOC <?= e($col['month_noc']) ?></span></dt>
                <dd><a href="<?= e($link('collection_month')) ?>"><?= e($money($col['month_prev'])) ?></a></dd></div>
            <div><dt>% of collection target<span class="th-sub">target <?= e($money($col['month_target'])) ?></span></dt>
                <dd class="<?= trim($tone($col['pct_target'])) ?>"><strong><?= e($pct($col['pct_target'])) ?></strong></dd></div>
            <div><dt>% of opening outstanding</dt><dd><?= e($pct($col['pct_opening'])) ?></dd></div>
            <div><dt>Today collection<span class="th-sub"><?= e($per['today']) ?> · NOB <?= e($col['today_nob']) ?> · NOC <?= e($col['today_noc']) ?></span></dt>
                <dd><a href="<?= e($link('collection_today')) ?>"><?= e($money($col['today'])) ?></a></dd></div>
        </dl>
        <h3 class="bp-sub">Outstanding <span class="muted small">as on <?= e($per['as_on']) ?></span></h3>
        <dl class="bp-list">
            <div><dt>Overdue payment</dt><dd class="neg"><a href="<?= e($link('os_overdue')) ?>"><?= e($money($pos['os_overdue'])) ?></a></dd></div>
            <div><dt>90 days <span class="th-sub">91-150 days</span></dt><dd class="neg"><a href="<?= e($link('os_90')) ?>"><?= e($money($pos['os_90'])) ?></a></dd></div>
            <div><dt>150 days <span class="th-sub">over 150 days</span></dt><dd class="neg"><a href="<?= e($link('os_150')) ?>"><?= e($money($pos['os_150'])) ?></a></dd></div>
        </dl>
    </section>

    <section class="card bp-card" aria-labelledby="bp-dc">
        <h2 id="bp-dc" class="bp-title">4. Open DC · Samples</h2>
        <h3 class="bp-sub">Open DC <span class="muted small">as on <?= e($per['as_on']) ?></span></h3>
        <dl class="bp-list">
            <div><dt>With order</dt><dd><a href="<?= e($link('dc_order')) ?>"><?= e($money($pos['dc_order'])) ?></a></dd></div>
            <div><dt>Mail confirmation</dt><dd><a href="<?= e($link('dc_mail')) ?>"><?= e($money($pos['dc_mail'])) ?></a></dd></div>
            <div><dt>Rep's inform</dt><dd><a href="<?= e($link('dc_rep_inform')) ?>"><?= e($money($pos['dc_rep_inform'])) ?></a></dd></div>
            <div class="bp-total"><dt>Total open DC</dt><dd><?= e($money($pos['dc_order'] + $pos['dc_mail'] + $pos['dc_rep_inform'])) ?></dd></div>
        </dl>
        <h3 class="bp-sub">Samples <span class="muted small">as on <?= e($per['as_on']) ?></span></h3>
        <dl class="bp-list">
            <div><dt>Returnable</dt><dd><a href="<?= e($link('sample_returnable')) ?>"><?= e($money($pos['sample_returnable'])) ?></a></dd></div>
            <div><dt>Non-returnable</dt><dd><a href="<?= e($link('sample_non_returnable')) ?>"><?= e($money($pos['sample_non_returnable'])) ?></a></dd></div>
        </dl>
    </section>
</div>
<p class="muted small">Positions (pending orders, enquiries, open DC, samples, outstanding) use each sales employee's latest figure entered on or before <?= e($per['as_on']) ?>.
    Figures come from the daily entry sheets (<strong>+ ADD</strong>). For bill-wise figures from uploaded bills, open <a href="<?= e(url('/') . '?' . $ctx->query(['view' => 'bills'])) ?>">Bill-wise detail</a>.</p>
