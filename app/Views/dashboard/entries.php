<?php
/** @var string $kind */
/** @var string $metric */
/** @var string $title_page */
/** @var string $period */
/** @var list<array<string, mixed>> $rows */
/** @var \App\Modules\Dashboard\DashboardContext $ctx */
use App\Core\Money;

$back = url('/') . '?' . $ctx->query([]);
$sum = 0;
$nob = 0;
$noc = 0;
$isCount = ($isCount ?? false) || in_array($metric, ['lead_new_customer', 'lead_new_product'], true);
$fmt = static fn (int $v): string => $isCount ? (string) $v : rupees($v);
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e($back) ?>">Branch Performance</a></p>
        <h1><?= e($title_page) ?></h1>
        <p class="muted small"><?= e($period) ?> · from the daily entry sheets</p>
    </div>
</div>

<section class="card table-card">
    <?php if ($rows === []): ?>
        <p class="empty">Nothing entered for this selection.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <?php if ($kind === 'flow'): ?>
            <thead><tr><th>Date</th><th>Sales employee</th><th>Branch</th><th class="right"><?= $isCount ? 'Count' : 'Value' ?></th>
                <?php if (!empty($count)): ?><th class="right">NOB</th><th class="right">NOC</th><?php endif; ?><th>Entered by</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $v = $isCount ? (int) $r['value'] : Money::fromDb($r['value']); $sum += $v; $nob += (int) ($r['nob'] ?? 0); $noc += (int) ($r['noc'] ?? 0); ?>
                <tr><td class="nowrap"><?= e(date('d-m-Y', strtotime($r['entry_date']))) ?></td><td><?= e(($r['short_name'] ?: '') . ' - ' . $r['employee']) ?></td>
                    <td class="small"><?= e($r['branch']) ?></td><td class="right num"><?= e($fmt($v)) ?></td>
                    <?php if (!empty($count)): ?><td class="right num"><?= e($r['nob']) ?></td><td class="right num"><?= e($r['noc']) ?></td><?php endif; ?>
                    <td class="small muted"><?= e($r['entered_by'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th colspan="3">Total (<?= e(count($rows)) ?> entries)</th><th class="right num"><?= e($fmt($sum)) ?></th>
                <?php if (!empty($count)): ?><th class="right num"><?= e($nob) ?></th><th class="right num"><?= e($noc) ?></th><?php endif; ?><th></th></tr></tfoot>
        <?php elseif ($kind === 'opening'): ?>
            <thead><tr><th>Sales employee</th><th>Branch</th><th class="right">Opening outstanding</th><th>Entered by</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $v = Money::fromDb($r['value']); $sum += $v; ?>
                <tr><td><?= e(($r['short_name'] ?: '') . ' - ' . $r['employee']) ?></td><td class="small"><?= e($r['branch']) ?></td>
                    <td class="right num"><?= e(rupees($v)) ?></td><td class="small muted"><?= e($r['entered_by'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th colspan="2">Total</th><th class="right num"><?= e(rupees($sum)) ?></th><th></th></tr></tfoot>
        <?php else: ?>
            <thead><tr><th>Sales employee</th><th class="right"><?= $isCount ? 'Count' : 'Value' ?></th><th>Figure entered on</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $v = (int) $r[$metric]; $sum += $v; ?>
                <tr><td><?= e($r['label']) ?></td><td class="right num"><?= e($fmt($v)) ?></td>
                    <td class="small<?= $r[$metric . '_date'] !== null && $r[$metric . '_date'] < $ctx->asOn->format('Y-m-d') ? ' muted' : '' ?>">
                        <?= $r[$metric . '_date'] ? e(date('d-m-Y', strtotime($r[$metric . '_date']))) : '<span class="muted">never</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th>Total</th><th class="right num"><?= e($fmt($sum)) ?></th><th></th></tr></tfoot>
        <?php endif; ?>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
