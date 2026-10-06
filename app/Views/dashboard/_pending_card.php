<?php
/**
 * Step A3 - BRANCH PENDING ORDER card.
 * @var array<string, mixed> $pending  PendingOrderKpi::summary()
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var bool $canPendingDetail
 */
$pk = $pending;
$pDetail = static fn (array $o = []): string => url('dashboard/pending') . '?' . $ctx->query($o);
$pLink = static fn (string $text, array $o = []): string => $canPendingDetail ? '<a href="' . e($pDetail($o)) . '">' . $text . '</a>' : $text;
$maxBucket = max(1, ...array_column($pk['aging'], 'value'));
?>
<article class="card kpi-card kpi-live" data-kpi="pending">
    <header class="kpi-card-head">
        <h3><?= $canPendingDetail ? '<a href="' . e($pDetail()) . '" class="kpi-title-link">Branch Pending Order</a>' : 'Branch Pending Order' ?></h3>
        <span class="badge badge-muted">as on <?= e($ctx->asOn->format('d-m-Y')) ?></span>
    </header>

    <div class="kpi-hero">
        <span class="kpi-hero-label">Total pending order value</span>
        <span class="kpi-hero-value"><?= $pLink(e(rupees($pk['value']))) ?></span>
        <span class="kpi-hero-sub"><strong><?= e($pk['orders']) ?></strong> order(s) · <strong><?= e($pk['customers']) ?></strong> customer(s)</span>
    </div>

    <dl class="kpi-lines">
        <div>
            <dt>Current pending order<span class="period">ordered in <?= e($pk['month_name']) ?> · <?= e($pk['current_orders']) ?> order(s)</span></dt>
            <dd><?= e(rupees($pk['current_value'])) ?></dd>
        </div>
        <div>
            <dt>Oldest pending order<span class="period"><?= $pk['oldest'] ? e($pk['oldest']['order_no'] . ' · ' . $pk['oldest']['customer']) : 'None' ?></span></dt>
            <dd class="<?= $pk['oldest'] && $pk['oldest']['age'] > 90 ? 'bad' : '' ?>"><?= $pk['oldest'] ? e($pk['oldest']['age']) . ' days' : '—' ?></dd>
        </div>
    </dl>

    <div class="aging" aria-label="Pending order aging">
        <p class="aging-title">Aging</p>
        <?php foreach ($pk['aging'] as $b): ?>
            <div class="aging-row">
                <span class="aging-label"><?= e($b['label']) ?></span>
                <span class="aging-bar"><span class="aging-fill" data-width="<?= e(round($b['value'] * 100 / $maxBucket)) ?>"></span></span>
                <span class="aging-value"><?= $pLink(e(rupees($b['value'])), ['bucket' => $b['key']]) ?><small><?= e($b['orders']) ?></small></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($canPendingDetail): ?>
        <a class="kpi-more" href="<?= e($pDetail()) ?>">View details →</a>
    <?php endif; ?>
</article>
