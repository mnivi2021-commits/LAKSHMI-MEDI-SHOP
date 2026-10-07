<?php
/** @var string $type */
/** @var DateTimeImmutable $date */
/** @var DateTimeImmutable $month */
/** @var DateTimeImmutable $today */
/** @var int|null $branch */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $reps */
/** @var array<int, array<string, mixed>> $values */
/** @var array<int, array<string, string>> $hints */
/** @var array<string, string> $errors */
/** @var bool $monthDone */
/** @var bool $canDay */
/** @var bool $canMonth */
use App\Core\Csrf;
use App\Modules\Dashboard\EntryController;
use App\Modules\Dashboard\Kpi\BranchPerformance;

$isDay = $type === 'day';
$groups = [];
foreach (EntryController::DAY_FIELDS as $f => [$g]) {
    $groups[$g] = ($groups[$g] ?? 0) + 1;
}
$q = static fn (array $extra): string => url('entry') . '?' . http_build_query(array_filter(array_merge(['branch' => $branch], $extra), static fn ($v) => $v !== null && $v !== ''));
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('/')) ?>">Branch Performance</a></p>
        <h1><?= $isDay ? 'Daily entry · ' . e($date->format('d-m-Y')) : 'Month start · ' . e($month->format('F Y')) ?></h1>
        <p class="muted small"><?= $isDay
            ? 'One row per sales employee. Type the day\'s totals and the position at the end of the day. Blank position cells keep the last figure (shown in grey).'
            : 'Once a month: the targets for the month and the total outstanding on the 1st (one total per sales employee, not bill-wise).' ?></p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php if ($isDay && !$monthDone && $canMonth): ?>
    <div class="alert alert-warning">Month start figures for <?= e($month->format('F Y')) ?> are not complete. <a href="<?= e($q(['type' => 'month', 'month' => $month->format('Y-m')])) ?>">Enter targets and opening outstanding first</a>.</div>
<?php endif; ?>

<nav class="tabs" aria-label="Sheet">
    <?php if ($canMonth): ?><a href="<?= e($q(['type' => 'month', 'month' => $month->format('Y-m')])) ?>" class="<?= $isDay ? '' : 'active' ?>">Month start (targets, opening outstanding)</a><?php endif; ?>
    <?php if ($canDay): ?><a href="<?= e($q(['type' => 'day', 'date' => $date->format('Y-m-d')])) ?>" class="<?= $isDay ? 'active' : '' ?>">Daily entry (sales, collection, pending ...)</a><?php endif; ?>
</nav>

<form method="get" action="<?= e(url('entry')) ?>" class="filters card sheet-filters">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($isDay): ?>
        <label class="field"><span>Date</span><input type="date" name="date" value="<?= e($date->format('Y-m-d')) ?>" max="<?= e($today->format('Y-m-d')) ?>"></label>
    <?php else: ?>
        <label class="field"><span>Month</span><input type="month" name="month" value="<?= e($month->format('Y-m')) ?>"></label>
    <?php endif; ?>
    <label class="field"><span>Branch</span><select name="branch"><option value="">All my branches</option>
        <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === $branch ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
    <div class="filter-actions"><button type="submit" class="btn">Open</button></div>
</form>

<?php if ($errors): ?>
    <div class="alert alert-error">Please correct the red cells (hover a cell to see why).</div>
<?php endif; ?>

<?php if ($reps === []): ?>
    <p class="empty card">No active sales employees in your selection. Mark employees as Sales representative in HRM.</p>
<?php else: ?>
<form method="post" action="<?= e(url($isDay ? 'entry/day' : 'entry/month')) ?>" class="card table-card" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="<?= $isDay ? 'date' : 'month' ?>" value="<?= e($isDay ? $date->format('Y-m-d') : $month->format('Y-m')) ?>">
    <?php if ($branch): ?><input type="hidden" name="branch" value="<?= e($branch) ?>"><?php endif; ?>
    <div class="table-scroll">
    <table class="table compact sheet-table">
        <thead>
        <?php if ($isDay): ?>
            <tr>
                <th rowspan="2" class="sheet-name">Sales employee</th>
                <?php foreach ($groups as $g => $n): ?><th colspan="<?= e($n) ?>" class="center sheet-group"><?= e($g) ?></th><?php endforeach; ?>
            </tr>
            <tr><?php foreach (EntryController::DAY_FIELDS as $f => [, $head, $kind]): ?><th class="right" title="<?= e($kind === 'count' ? 'Number' : 'Amount in ₹') ?>"><?= e($head) ?></th><?php endforeach; ?></tr>
        <?php else: ?>
            <tr><th class="sheet-name">Sales employee</th><?php foreach (EntryController::MONTH_FIELDS as $f => $head): ?><th class="right"><?= e($head) ?> (₹)</th><?php endforeach; ?></tr>
        <?php endif; ?>
        </thead>
        <tbody>
        <?php foreach ($reps as $r): $id = (int) $r['id']; $v = $values[$id] ?? []; ?>
            <tr class="<?= !empty($v['_exists']) ? 'sheet-saved' : '' ?>">
                <th class="sheet-name"><?= e($r['short_name'] ?: $r['name']) ?><div class="th-sub"><?= e($r['branch']) ?><?= !empty($v['_exists']) ? ' · saved' : '' ?></div></th>
                <?php foreach (($isDay ? array_keys(EntryController::DAY_FIELDS) : array_keys(EntryController::MONTH_FIELDS)) as $f):
                    $err = $errors["{$id}.{$f}"] ?? null;
                    $isPos = $isDay && in_array($f, BranchPerformance::POSITIONS, true);
                    $count = $isDay && EntryController::DAY_FIELDS[$f][2] === 'count'; ?>
                    <td class="sheet-cell<?= $err ? ' sheet-error' : '' ?>">
                        <input type="text" name="rows[<?= e($id) ?>][<?= e($f) ?>]" value="<?= e($v[$f] ?? '') ?>"
                               inputmode="<?= $count ? 'numeric' : 'decimal' ?>" class="<?= $count ? 'sheet-count' : 'sheet-money' ?>"
                               aria-label="<?= e(($r['short_name'] ?: $r['name']) . ' ' . ($isDay ? EntryController::DAY_FIELDS[$f][0] . ' ' . EntryController::DAY_FIELDS[$f][1] : EntryController::MONTH_FIELDS[$f])) ?>"
                               <?= $isPos && isset($hints[$id][$f]) ? 'placeholder="' . e($hints[$id][$f]) . '"' : '' ?>
                               <?= $err ? 'title="' . e($err) . '"' : '' ?>>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="form-actions padded">
        <button type="submit" class="btn btn-primary">Save sheet</button>
        <a class="btn" href="<?= e(url('/') . ($branch ? '?branch=' . $branch : '')) ?>">Back to dashboard</a>
        <span class="muted small">NOB = number of bills · NOC = number of customers · amounts in ₹ (commas allowed).</span>
    </div>
</form>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
