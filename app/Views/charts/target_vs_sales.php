<?php
/**
 * Month-wise sales (bars) against target (tick marks) - one y-axis, server-rendered SVG.
 * Hover: native <title> tooltip per month (no inline script under CSP). The month
 * table on the same page is the accessible table view of this chart.
 *
 * @var list<array{month: string, label: string, target: ?int, sales: ?int}> $monthly
 * @var bool $showTarget
 * @var string|null $barLabel   default "Sales"
 * @var string|null $tickLabel  default "Target"
 */
$barLabel ??= 'Sales';
$tickLabel ??= 'Target';
$W = 720; $H = 260; $padL = 56; $padR = 8; $padT = 12; $padB = 28;
$plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;

$max = 0;
foreach ($monthly as $m) {
    $max = max($max, (int) $m['sales'], $showTarget ? (int) $m['target'] : 0);
}
// "Nice" axis maximum: a round step x a power of ten (in paise), close above the largest value.
$niceMax = 100;
if ($max > 0) {
    $exp = 10 ** (int) floor(log10($max));
    foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $s) {
        if ($s * $exp >= $max) { $niceMax = (int) ($s * $exp); break; }
    }
}
$y = static fn (int $v): float => $padT + $plotH - ($v / $niceMax) * $plotH;
$slot = $plotW / count($monthly);
$barW = min(28, $slot * 0.55);
$hasNegative = false;
?>
<figure class="chart" aria-label="Month-wise sales against target">
    <div class="chart-legend" aria-hidden="true">
        <span><i class="key key-sales"></i><?= e($barLabel) ?></span>
        <?php if ($showTarget): ?><span><i class="key key-target"></i><?= e($tickLabel) ?></span><?php endif; ?>
    </div>
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" preserveAspectRatio="xMidYMid meet" class="chart-svg">
        <title>Month-wise <?= e(strtolower($barLabel)) ?><?= $showTarget ? ' against ' . e(strtolower($tickLabel)) : '' ?></title>
        <?php for ($i = 0; $i <= 4; $i++): $v = (int) ($niceMax * $i / 4); $gy = $y($v); ?>
            <line x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= round($gy, 1) ?>" y2="<?= round($gy, 1) ?>" class="grid<?= $i === 0 ? ' baseline' : '' ?>"/>
            <text x="<?= $padL - 8 ?>" y="<?= round($gy + 4, 1) ?>" class="axis-label" text-anchor="end"><?= e(rupees_short($v)) ?></text>
        <?php endfor; ?>

        <?php foreach ($monthly as $i => $m):
            $cx = $padL + $slot * $i + $slot / 2;
            $sales = $m['sales'];
            $tip = $m['label'] . ' ' . substr($m['month'], 0, 4) . ' — ' . $barLabel . ': ' . ($sales === null ? 'not yet' : rupees($sales))
                 . ($showTarget ? ' · ' . $tickLabel . ': ' . rupees($m['target']) : '');
        ?>
            <g class="month">
                <title><?= e($tip) ?></title>
                <rect x="<?= round($cx - $slot / 2, 1) ?>" y="<?= $padT ?>" width="<?= round($slot, 1) ?>" height="<?= $plotH ?>" class="hit"/>
                <?php if ($sales !== null && $sales > 0):
                    $top = $y($sales); $h = $padT + $plotH - $top; $r = min(4, $h, $barW / 2);
                    $x0 = $cx - $barW / 2; $x1 = $cx + $barW / 2; $base = $padT + $plotH;
                ?>
                    <path class="bar-sales" d="M<?= round($x0, 1) ?>,<?= $base ?> V<?= round($top + $r, 1) ?> Q<?= round($x0, 1) ?>,<?= round($top, 1) ?> <?= round($x0 + $r, 1) ?>,<?= round($top, 1) ?> H<?= round($x1 - $r, 1) ?> Q<?= round($x1, 1) ?>,<?= round($top, 1) ?> <?= round($x1, 1) ?>,<?= round($top + $r, 1) ?> V<?= $base ?> Z"/>
                <?php elseif ($sales !== null && $sales < 0): $hasNegative = true; endif; ?>
                <?php if ($showTarget && (int) $m['target'] > 0): $ty = round($y((int) $m['target']), 1); ?>
                    <line class="tick-target" x1="<?= round($cx - $barW / 2 - 6, 1) ?>" x2="<?= round($cx + $barW / 2 + 6, 1) ?>" y1="<?= $ty ?>" y2="<?= $ty ?>"/>
                <?php endif; ?>
                <text x="<?= round($cx, 1) ?>" y="<?= $H - 8 ?>" class="axis-label<?= $sales === null ? ' future' : '' ?>" text-anchor="middle"><?= e($m['label']) ?></text>
            </g>
        <?php endforeach; ?>
    </svg>
    <?php if ($hasNegative): ?><figcaption class="muted small">A month with net returns (credit notes larger than sales) is shown without a bar.</figcaption><?php endif; ?>
</figure>
