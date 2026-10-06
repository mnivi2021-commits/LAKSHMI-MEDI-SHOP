<?php
/** @var \App\Modules\Dashboard\DashboardContext $ctx */
/** @var string $by */
/** @var array{rows: list<array<string, mixed>>, totals: array<string, mixed>, columns: list<string>} $grid */
/** @var array<int, string> $fyOptions */
/** @var array<string, string> $months */
/** @var array<int, string> $branches */
/** @var array<int, string> $employees */
use App\Core\Gate;
use App\Modules\Sales\SalesDetailsController;
use App\Modules\Sales\SalesGrid;

$f = $ctx->filters;
$cols = $grid['columns'];
$hasPct = in_array('target', $cols, true) && in_array('sales', $cols, true);
$canDash = Gate::allows('dashboard.view');

// Drill-down target for one cell: the dashboard page that lists the source records.
$drill = static function (string $col, array $row) use ($ctx, $by, $canDash): ?string {
    $key = $row['key'] ?? null;
    if ($key === null || ($by === 'employee' && (int) $key === 0)) {
        return null;                      // unassigned rows / totals use the filter-level link
    }
    $o = match ($by) {
        'branch'   => ['branch' => (int) $key],
        'employee' => ['employee' => (int) $key],
        default    => ['month' => (string) $key],
    };
    $q = static fn (array $extra = []): string => '?' . $ctx->query($o + $extra);
    return match ($col) {
        'sales'       => $canDash ? url('dashboard/sales') . $q($by === 'month' ? ['w' => 'mtd'] : ['w' => 'fy']) : null,
        'collection'  => $canDash ? url('dashboard/collection') . $q(['w' => $by === 'month' ? 'month' : 'fy']) : null,
        'pending'     => $canDash ? url('dashboard/pending') . $q() : null,
        'outstanding' => $canDash ? url('dashboard/outstanding') . $q() : null,
        'd90'         => $canDash ? url('dashboard/outstanding') . $q(['cat' => 'd90']) : null,
        'd150'        => $canDash ? url('dashboard/outstanding') . $q(['cat' => 'd150']) : null,
        'samples'     => url('sales/documents/samples') . $q(),
        'dc'          => url('sales/documents/dc') . $q(),
        default       => null,
    };
};
$cell = static function (string $col, array $row) use ($drill): string {
    $v = (int) $row[$col];
    $text = e($v === 0 ? '—' : rupees($v));
    $href = $v !== 0 ? $drill($col, $row) : null;
    return $href ? '<a href="' . e($href) . '">' . $text . '</a>' : $text;
};
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Sales Details</p>
        <h1>Performance by <?= e(strtolower(SalesGrid::GROUPS[$by])) ?></h1>
        <p class="muted small"><?= e($ctx->fyRow['label']) ?> · as on <?= e($ctx->asOn->format('d-m-Y')) ?><?= $ctx->isLive ? ' (live)' : '' ?></p>
    </div>
    <div class="form-actions">
        <?php if ($canExport): ?><a class="btn" href="<?= e(url('sales/export') . '?' . $ctx->query(['by' => $by])) ?>">Export CSV</a><?php endif; ?>
    </div>
</div>

<?php foreach ($ctx->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<form method="get" action="<?= e(url('sales')) ?>" class="filters filters-5 card">
    <label class="field">
        <span>Financial year</span>
        <select name="fy">
            <?php foreach ($fyOptions as $id => $lbl): ?><option value="<?= e($id) ?>"<?= $id === $f['fy_id'] ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Month (as on)</span>
        <select name="month">
            <option value="">Whole year</option>
            <?php foreach ($months as $val => $lbl): ?><option value="<?= e($val) ?>"<?= $val === $f['month'] ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Branch</span>
        <select name="branch">
            <?php if (count($branches) !== 1): ?><option value="">All branches</option><?php endif; ?>
            <?php foreach ($branches as $id => $lbl): ?><option value="<?= e($id) ?>"<?= $id === $f['branch_id'] ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Sales employee</span>
        <select name="employee">
            <?php if (count($employees) !== 1): ?><option value="">All employees</option><?php endif; ?>
            <?php foreach ($employees as $id => $lbl): ?><option value="<?= e($id) ?>"<?= $id === $f['employee_id'] ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <input type="hidden" name="by" value="<?= e($by) ?>">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a class="btn" href="<?= e(url('sales')) ?>">Clear</a>
    </div>
</form>

<nav class="tabs" aria-label="Group by">
    <?php foreach (SalesGrid::GROUPS as $key => $lbl): ?>
        <a href="<?= e(url('sales') . '?' . $ctx->query(['by' => $key])) ?>" class="<?= $key === $by ? 'active' : '' ?>"<?= $key === $by ? ' aria-current="page"' : '' ?>>By <?= e(strtolower($lbl)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card table-card">
    <?php if ($cols === []): ?>
        <p class="empty">You do not have permission to view any of these figures.</p>
    <?php elseif ($grid['rows'] === []): ?>
        <p class="empty">No figures for these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact grid-table">
        <thead><tr>
            <th><?= e(SalesGrid::GROUPS[$by]) ?></th>
            <?php foreach ($cols as $c): ?><th class="right"><?= e(SalesDetailsController::COLUMNS[$c][0]) ?></th><?php if ($c === 'sales' && $hasPct): ?><th class="right">Achieved</th><?php endif; ?><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($grid['rows'] as $r): ?>
            <tr>
                <td class="nowrap"><?= e($r['label']) ?></td>
                <?php foreach ($cols as $c): ?>
                    <td class="right num<?= in_array($c, ['d90', 'd150'], true) && $r[$c] > 0 ? ' neg' : '' ?>"><?= $cell($c, $r) ?></td>
                    <?php if ($c === 'sales' && $hasPct): ?><td class="right num"><?= $r['achieved_pct'] === null ? '—' : e(number_format($r['achieved_pct'], 1)) . '%' ?></td><?php endif; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <th>Total</th>
                <?php foreach ($cols as $c): $t = (int) $grid['totals'][$c]; ?>
                    <th class="right num"><?= e($t === 0 ? '—' : rupees($t)) ?></th>
                    <?php if ($c === 'sales' && $hasPct): ?><th class="right num"><?= $grid['totals']['achieved_pct'] === null ? '—' : e(number_format($grid['totals']['achieved_pct'], 1)) . '%' ?></th><?php endif; ?>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>
    </div>
    <p class="muted small padded">
        Target is the full financial-year target. Totals match the dashboard for the same filters. Sales and collection run from the FY start to the as-on date;
        pending, samples, DC and outstanding are balances as on that date<?= $by === 'month' ? ' and are not split by month' : '' ?>.
        Outstanding age is measured in <?= e(\App\Modules\Dashboard\Kpi\OpenBills::ageBasisLabel()) ?>. Click a figure to see the records behind it.
    </p>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
