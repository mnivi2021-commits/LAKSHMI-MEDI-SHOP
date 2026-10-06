<?php
/**
 * Step A2 - PAYMENT COLLECTION card.
 * @var array<string, mixed> $collection  CollectionKpi::summary()
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var bool $canCollectionDetail
 */
$ck = $collection;
$cDetail = static fn (string $w = 'month'): string => url('dashboard/collection') . '?' . $ctx->query(['w' => $w]);
$cLink = static fn (string $text, string $w): string => $canCollectionDetail ? '<a href="' . e($cDetail($w)) . '">' . $text . '</a>' : $text;
?>
<article class="card kpi-card kpi-live" data-kpi="collection">
    <header class="kpi-card-head">
        <h3><?= $canCollectionDetail ? '<a href="' . e($cDetail()) . '" class="kpi-title-link">Payment Collection</a>' : 'Payment Collection' ?></h3>
        <span class="badge badge-muted"><?= e($ck['month_name']) ?></span>
    </header>

    <?php if (!$ck['applies']): ?>
        <p class="kpi-pending"><?= e($ck['not_applicable_note']) ?></p>
    <?php else: ?>
        <div class="kpi-hero">
            <span class="kpi-hero-label">Total collection · <?= e($ck['month_name']) ?></span>
            <span class="kpi-hero-value"><?= $cLink(e(rupees($ck['total'])), 'month') ?></span>
            <?php if ($ck['collection_pct'] !== null): ?>
                <div class="progress" role="progressbar" aria-valuenow="<?= e($ck['collection_pct']) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Collection target achieved">
                    <span class="progress-fill progress-collection<?= $ck['collection_pct'] >= 100 ? ' done' : '' ?>" data-width="<?= e(min(100, $ck['collection_pct'])) ?>"></span>
                </div>
                <span class="kpi-hero-sub"><strong><?= e(number_format($ck['collection_pct'], 1)) ?>%</strong> of <?= e($ck['month_name']) ?> collection target</span>
            <?php endif; ?>
        </div>

        <dl class="kpi-lines">
            <div>
                <dt>Previous period collection<span class="period"><?= e($ck['periods']['month_to_previous_day'] ?? 'First day of the month') ?></span></dt>
                <dd><?= $cLink(e(rupees($ck['previous_period'])), 'prev') ?></dd>
            </div>
            <div>
                <dt><?= $ck['is_live'] ? "Today's collection" : 'Collection on ' . e($ctx->asOn->format('d-m-Y')) ?><span class="period"><?= e($ck['periods']['today']) ?> · <?= e($ck['payments_today']) ?> payment(s)</span></dt>
                <dd><?= $cLink(e(rupees($ck['today'])), 'today') ?></dd>
            </div>
            <div><dt>Collection target<span class="period"><?= e($ck['month_name']) ?></span></dt>
                <dd><?= $ck['collection_target'] === null ? '<span class="muted" title="' . e($ck['target_note']) . '">n/a</span>' : e(rupees($ck['collection_target'])) ?></dd></div>
            <?php if ($ck['collection_pending'] !== null): ?>
                <div><dt>Collection pending</dt><dd><?= e(rupees($ck['collection_pending'])) ?></dd></div>
            <?php endif; ?>
            <div><dt>Customers · payments<span class="period">this month</span></dt><dd><?= e($ck['customers']) ?> · <?= e($ck['payments']) ?></dd></div>
            <div>
                <dt>Overdue collection<span class="period"><?= e($ck['overdue_bills']) ?> bill(s) · <?= e($ck['overdue_customers']) ?> customer(s)</span></dt>
                <dd class="<?= $ck['overdue'] > 0 ? 'bad' : '' ?>"><?= $canCollectionDetail ? '<a href="' . e($cDetail() . '#overdue') . '">' . e(rupees($ck['overdue'])) . '</a>' : e(rupees($ck['overdue'])) ?></dd>
            </div>
        </dl>
    <?php endif; ?>

    <?php if ($canCollectionDetail): ?>
        <a class="kpi-more" href="<?= e($cDetail()) ?>">View details →</a>
    <?php endif; ?>
</article>
