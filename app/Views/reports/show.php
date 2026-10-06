<?php
/** @var \App\Modules\Reports\Report $report */
/** @var \App\Modules\Reports\ReportFilters $f */
/** @var list<array<string, mixed>> $rows */
/** @var int $count */
/** @var array<string, mixed> $totals */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $employees */
use App\Modules\Reports\ReportController;

$uses = $report->filters();
$cols = $report->columns();
$qs = http_build_query($f->query());
$fmt = static function (mixed $v, string $type): string {
    if ($v === null || $v === '') {
        return '—';
    }
    return match ($type) {
        'money' => e(rupees((int) $v)),
        'date'  => e(date('d-m-Y', strtotime((string) $v))),
        'pct'   => e(number_format((float) $v, 1)) . '%',
        'int'   => e(number_format((int) $v)),
        default => e((string) $v),
    };
};
$right = static fn (string $type): bool => in_array($type, ['money', 'int', 'qty', 'pct'], true);
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('reports')) ?>">Reports</a> · <?= e($report->group()) ?></p>
        <h1><?= e($report->title()) ?></h1>
        <p class="muted small"><?= e($report->description()) ?></p>
    </div>
    <?php if ($canExport): ?>
        <div class="form-actions">
            <a class="btn" href="<?= e(url('reports/' . $report->key() . '/export/xlsx') . '?' . $qs) ?>">Excel</a>
            <a class="btn" href="<?= e(url('reports/' . $report->key() . '/export/csv') . '?' . $qs) ?>">CSV</a>
        </div>
    <?php endif; ?>
</div>

<?php foreach ($f->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<form method="get" action="<?= e(url('reports/' . $report->key())) ?>" class="filters filters-5 card">
    <?php if (in_array('period', $uses, true)): ?>
        <label class="field"><span>From</span><input type="date" name="from" value="<?= e($f->from) ?>"></label>
        <label class="field"><span>To</span><input type="date" name="to" value="<?= e($f->to) ?>"></label>
    <?php endif; ?>
    <?php if (in_array('as_on', $uses, true)): ?>
        <label class="field"><span>As on</span><input type="date" name="as_on" value="<?= e($f->asOn) ?>"></label>
    <?php endif; ?>
    <?php if (in_array('branch', $uses, true)): ?>
        <label class="field"><span>Branch</span><select name="branch"><option value="">All</option>
            <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === $f->branchId ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <?php if (in_array('employee', $uses, true)): ?>
        <label class="field"><span>Employee</span><select name="employee"><option value="">All</option>
            <?php foreach ($employees as $em): ?><option value="<?= e($em['id']) ?>"<?= (int) $em['id'] === $f->employeeId ? ' selected' : '' ?>><?= e(($em['short_name'] ?: $em['name']) . ' - ' . $em['name']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <?php if (in_array('customer', $uses, true)): ?>
        <label class="field"><span>Customer code</span><input type="text" name="customer" value="<?= e($f->customerCode ?? '') ?>" class="uppercase" placeholder="All"></label>
    <?php endif; ?>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Show</button>
        <a class="btn" href="<?= e(url('reports/' . $report->key())) ?>">Reset</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e(number_format($count)) ?> row<?= $count === 1 ? '' : 's' ?>
        <span class="muted small"><?= in_array('period', $uses, true) ? e(date('d-m-Y', strtotime($f->from)) . ' to ' . date('d-m-Y', strtotime($f->to))) : 'as on ' . e(date('d-m-Y', strtotime($f->asOn))) ?></span></h2>
    <?php if ($rows === []): ?>
        <p class="empty">Nothing for these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact grid-table">
        <thead><tr><?php foreach ($cols as [$label, $type]): ?><th<?= $right($type) ? ' class="right"' : '' ?>><?= e($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($cols as $k => [, $type]): ?><td class="<?= $right($type) ? 'right num' : 'small' ?><?= $type === 'money' && (int) ($r[$k] ?? 0) < 0 ? ' neg' : '' ?>"><?= $fmt($r[$k] ?? null, $type) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($totals !== []): ?>
        <tfoot><tr class="total-row">
            <?php $first = true; foreach ($cols as $k => [, $type]): ?>
                <th class="<?= $right($type) ? 'right num' : '' ?>"><?= $first ? 'Total' : (array_key_exists($k, $totals) ? $fmt($totals[$k], $type) : '') ?></th>
            <?php $first = false; endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
    </table>
    </div>
    <?php if ($count > ReportController::SCREEN_LIMIT): ?>
        <p class="alert alert-info padded">Showing the first <?= e(number_format(ReportController::SCREEN_LIMIT)) ?> rows. The totals cover all <?= e(number_format($count)) ?> rows; download Excel for everything.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($note = $report->note($f)): ?><p class="muted small padded"><?= e($note) ?></p><?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
