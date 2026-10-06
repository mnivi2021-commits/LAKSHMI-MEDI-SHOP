<?php
/**
 * Step A1 - SALES PERFORMANCE card.
 * @var array<string, mixed> $sales  SalesKpi::summary()
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var bool $canSalesDetail
 */
$sk = $sales;
$detail = static fn (string $w = 'fy'): string => url('dashboard/sales') . '?' . $ctx->query(['w' => $w]);
$link = static function (string $text, string $w) use ($canSalesDetail, $detail): string {
    return $canSalesDetail ? '<a href="' . e($detail($w)) . '">' . $text . '</a>' : $text;
};
$pct = $sk['achieved_pct'];
$barPct = $pct === null ? 0 : min(100, $pct);
$prevDay = $sk['periods']['fy_to_previous_day'];
$monthPrev = $sk['periods']['month_to_previous_day'];
$prevDate = $prevDay ? substr($prevDay, -10) : null;   // "05-10-2026"
?>
<article class="card kpi-card kpi-live" data-kpi="sales">
    <header class="kpi-card-head">
        <h3><?= $canSalesDetail ? '<a href="' . e($detail()) . '" class="kpi-title-link">Sales Performance</a>' : 'Sales Performance' ?></h3>
        <span class="badge badge-muted" title="<?= $sk['basis'] === 'total' ? 'Invoice value including GST' : 'Taxable value, excluding GST' ?>"><?= $sk['basis'] === 'total' ? 'incl. GST' : 'excl. GST' ?></span>
    </header>

    <div class="kpi-hero">
        <span class="kpi-hero-label">Total sales <?= e($sk['fy_label']) ?></span>
        <span class="kpi-hero-value"><?= $link(e(rupees($sk['sales_total'])), 'fy') ?></span>
        <?php if ($pct !== null): ?>
            <div class="progress" role="progressbar" aria-valuenow="<?= e($pct) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Target achieved">
                <span class="progress-fill<?= $pct >= 100 ? ' done' : '' ?>" data-width="<?= e($barPct) ?>"></span>
            </div>
            <span class="kpi-hero-sub"><strong><?= e(number_format($pct, 1)) ?>%</strong> of annual target achieved</span>
        <?php endif; ?>
    </div>

    <dl class="kpi-lines">
        <div><dt>Annual target</dt><dd><?= $sk['annual_target'] === null ? '<span class="muted" title="' . e($sk['target_note']) . '">n/a</span>' : e(rupees($sk['annual_target'])) ?></dd></div>
        <div>
            <dt>Sales as on previous day<span class="period"><?= e($prevDay ?? 'No earlier days in this FY') ?></span></dt>
            <dd><?= $link(e(rupees($sk['sales_fy_to_previous_day'])), 'prev') ?></dd>
        </div>
        <div>
            <dt><?= e($sk['month_name']) ?> sales as on <?= e($prevDate ?? $ctx->asOn->format('d-m-Y')) ?><span class="period"><?= e($monthPrev ?? 'First day of the month') ?></span></dt>
            <dd><?= $link(e(rupees($sk['sales_month_to_previous_day'])), 'month') ?></dd>
        </div>
        <div>
            <dt><?= $sk['is_live'] ? "Today's sales" : 'Sales on ' . e($ctx->asOn->format('d-m-Y')) ?><span class="period"><?= e($sk['periods']['today']) ?> · <?= e($sk['documents_today']) ?> invoice(s)</span></dt>
            <dd><?= $link(e(rupees($sk['sales_today'])), 'today') ?></dd>
        </div>
        <?php if ($sk['annual_target'] !== null): ?>
            <div>
                <dt><?= $sk['target_exceeded_by'] > 0 ? 'Target exceeded by' : 'Target pending' ?></dt>
                <dd class="<?= $sk['target_exceeded_by'] > 0 ? 'good' : '' ?>"><?= e(rupees($sk['target_exceeded_by'] > 0 ? $sk['target_exceeded_by'] : $sk['target_pending'])) ?></dd>
            </div>
        <?php endif; ?>
        <div>
            <dt>Average monthly sales<span class="period"><?= $sk['completed_months'] ? e($sk['completed_months']) . ' completed month(s)' : 'No completed month yet' ?></span></dt>
            <dd><?= e(rupees($sk['average_monthly'])) ?></dd>
        </div>
        <?php if ($sk['annual_target'] !== null): ?>
            <div>
                <dt>Required monthly sales<span class="period">to reach target in <?= e($sk['remaining_months']) ?> month(s)</span></dt>
                <dd><?= e(rupees($sk['required_monthly'])) ?></dd>
            </div>
        <?php endif; ?>
    </dl>

    <?php if ($canSalesDetail): ?>
        <a class="kpi-more" href="<?= e($detail()) ?>">View details →</a>
    <?php endif; ?>
</article>
