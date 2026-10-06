<?php
/**
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<string, mixed> $s
 * @var list<array<string, mixed>> $monthly
 * @var list<array<string, mixed>> $byBranch
 * @var list<array<string, mixed>> $byEmployee
 * @var string $window
 * @var \App\Core\DateRange|null $range
 * @var array{rows: list<array<string, mixed>>, total: int, count: int} $docs
 * @var int $page
 * @var int $pages
 * @var bool $canExport
 */
use App\Core\View;
use App\Modules\Dashboard\SalesController;

$back = url('/') . '?' . $ctx->query();
$self = static fn (array $o): string => url('dashboard/sales') . '?' . $ctx->query($o);
$windowTotals = ['fy' => $s['sales_total'], 'prev' => $s['sales_fy_to_previous_day'], 'month' => $s['sales_month_to_previous_day'], 'today' => $s['sales_today'], 'mtd' => $s['sales_month_to_previous_day'] + $s['sales_today']];
$filterChips = array_filter([
    'Month'    => $ctx->filters['month'] ? date('M Y', strtotime($ctx->filters['month'] . '-01')) : null,
    'Branch'   => $ctx->filters['branch_id'] ? ($ctx->branchOptions()[$ctx->filters['branch_id']] ?? null) : null,
    'Employee' => $ctx->filters['employee_id'] ? ($ctx->employeeOptions(null)[$ctx->filters['employee_id']] ?? null) : null,
    'Customer' => $ctx->customerLabel,
    'Product'  => $ctx->productLabel,
]);
$cum = 0; $cumT = 0;
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e($back) ?>">Dashboard</a> / Step A1</p>
        <h1>Sales Performance</h1>
        <p class="muted asof">
            As on <strong><?= e($ctx->asOn->format('d-m-Y')) ?></strong> · <?= e($s['fy_label']) ?> ·
            <?= $s['basis'] === 'total' ? 'Invoice value incl. GST' : 'Taxable value excl. GST' ?> · credit notes deducted
            <?php foreach ($filterChips as $k => $v): ?><span class="chip"><?= e($k) ?>: <?= e($v) ?></span><?php endforeach; ?>
        </p>
    </div>
    <a class="btn" href="<?= e($back) ?>">← Back to dashboard</a>
</div>

<?php foreach ($ctx->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<section class="stat-grid" aria-label="Summary">
    <div class="card stat"><span>Annual target</span><strong><?= $s['annual_target'] === null ? 'n/a' : e(rupees($s['annual_target'])) ?></strong>
        <?php if ($s['target_note']): ?><small class="muted"><?= e($s['target_note']) ?></small><?php endif; ?></div>
    <div class="card stat"><span>Total sales (FY to date)</span><strong><?= e(rupees($s['sales_total'])) ?></strong><small class="muted"><?= e($s['documents_fy']) ?> documents · <?= e($s['customers_fy']) ?> customers</small></div>
    <div class="card stat"><span>Target achieved</span><strong><?= $s['achieved_pct'] === null ? 'n/a' : e(number_format($s['achieved_pct'], 1)) . '%' ?></strong></div>
    <div class="card stat"><span><?= $s['target_exceeded_by'] > 0 ? 'Target exceeded by' : 'Target pending' ?></span><strong><?= e(rupees($s['target_exceeded_by'] > 0 ? $s['target_exceeded_by'] : $s['target_pending'])) ?></strong></div>
    <div class="card stat"><span>Sales as on previous day</span><strong><?= e(rupees($s['sales_fy_to_previous_day'])) ?></strong><small class="muted"><?= e($s['periods']['fy_to_previous_day'] ?? '—') ?></small></div>
    <div class="card stat"><span><?= e($s['month_name']) ?> sales to previous day</span><strong><?= e(rupees($s['sales_month_to_previous_day'])) ?></strong><small class="muted"><?= e($s['periods']['month_to_previous_day'] ?? 'First day of month') ?></small></div>
    <div class="card stat"><span><?= $s['is_live'] ? "Today's sales" : 'Sales on as-on day' ?></span><strong><?= e(rupees($s['sales_today'])) ?></strong><small class="muted"><?= e($s['periods']['today']) ?></small></div>
    <div class="card stat"><span>Average / required monthly</span><strong><?= e(rupees($s['average_monthly'])) ?></strong><small class="muted">Needed: <?= e(rupees($s['required_monthly'])) ?> × <?= e($s['remaining_months']) ?> month(s)</small></div>
</section>

<section class="card">
    <h2>Month-wise sales against target</h2>
    <?= View::render('charts/target_vs_sales', ['monthly' => $monthly, 'showTarget' => $s['annual_target'] !== null]) ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Month</th><th class="right">Target</th><th class="right">Sales</th><th class="right">Achieved</th><th class="right">Cumulative target</th><th class="right">Cumulative sales</th></tr></thead>
        <tbody>
        <?php foreach ($monthly as $m): $cumT += (int) $m['target']; if ($m['sales'] !== null) { $cum += $m['sales']; } ?>
            <tr class="<?= $m['sales'] === null ? 'future' : '' ?>">
                <td><?= e($m['label'] . ' ' . substr($m['month'], 0, 4)) ?></td>
                <td class="right num"><?= $m['target'] === null ? '—' : e(rupees($m['target'])) ?></td>
                <td class="right num"><?= $m['sales'] === null ? '<span class="muted">—</span>' : e(rupees($m['sales'])) ?></td>
                <td class="right num"><?= $m['sales'] !== null && $m['target'] ? e(number_format($m['sales'] * 100 / $m['target'], 1)) . '%' : '—' ?></td>
                <td class="right num"><?= $m['target'] === null ? '—' : e(rupees($cumT)) ?></td>
                <td class="right num"><?= $m['sales'] === null ? '—' : e(rupees($cum)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<div class="split">
    <?php foreach (['Branch' => $byBranch, 'Sales employee' => $byEmployee] as $title => $rows): ?>
    <section class="card table-card">
        <h2>By <?= e(strtolower($title)) ?></h2>
        <?php if ($rows === []): ?><p class="empty">No sales in this view yet.</p><?php else: ?>
        <div class="table-scroll">
        <table class="table compact">
            <thead><tr><th><?= e($title) ?></th><th class="right">FY to date</th><th class="right"><?= e($s['month_name']) ?></th><th class="right"><?= $s['is_live'] ? 'Today' : 'As-on day' ?></th><th class="right">Target</th><th class="right">Achieved</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['label']) ?></td>
                    <td class="right num"><?= e(rupees($r['fy_to_date'])) ?></td>
                    <td class="right num"><?= e(rupees($r['month_to_date'])) ?></td>
                    <td class="right num"><?= e(rupees($r['on_day'])) ?></td>
                    <td class="right num"><?= $r['target'] === null ? '—' : e(rupees($r['target'])) ?></td>
                    <td class="right num"><?= $r['achieved_pct'] === null ? '—' : e(number_format($r['achieved_pct'], 1)) . '%' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<section class="card table-card" id="documents">
    <div class="matrix-head">
        <h2>Invoices &amp; credit notes behind the figures</h2>
        <?php if ($canExport && $range): ?>
            <a class="btn btn-sm" href="<?= e(url('dashboard/sales/export') . '?' . $ctx->query(['w' => $window])) ?>">Export CSV</a>
        <?php endif; ?>
    </div>
    <nav class="tabs padded-x" aria-label="Period">
        <?php foreach (SalesController::WINDOWS as $key => [$lbl]): ?>
            <a href="<?= e($self(['w' => $key]) . '#documents') ?>" class="<?= $key === $window ? 'active' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($range === null): ?>
        <p class="empty">There are no days in this period yet (as-on date is the first day of the <?= $window === 'month' ? 'month' : 'year' ?>).</p>
    <?php else: ?>
        <p class="padded small">
            <?= e($range->label()) ?> · <?= e($docs['count']) ?> document(s) · total <strong><?= e(rupees($docs['total'], 2)) ?></strong>
            <?php if ($docs['total'] === $windowTotals[$window]): ?><span class="badge badge-ok" title="This list adds up to the same figure as the dashboard">matches dashboard</span>
            <?php else: ?><span class="badge badge-fail">does not match dashboard</span><?php endif; ?>
        </p>
        <?php if ($docs['rows'] === []): ?><p class="empty">No invoices in this period.</p><?php else: ?>
        <div class="table-scroll">
        <table class="table compact">
            <thead><tr><th>Date</th><th>Document</th><th>Customer</th><th>Branch</th><th>Employee</th><th class="right">Taxable</th><th class="right">Total incl. GST</th></tr></thead>
            <tbody>
            <?php foreach ($docs['rows'] as $r): $isCn = $r['document_type'] === 'credit_note'; ?>
                <tr>
                    <td class="nowrap"><?= e(date('d-m-Y', strtotime($r['invoice_date']))) ?></td>
                    <td class="nowrap"><?= e($r['invoice_no']) ?><?= $isCn ? ' <span class="badge badge-warn">credit note</span>' : '' ?></td>
                    <td><?= e($r['customer']) ?> <span class="muted small"><?= e($r['customer_code']) ?></span></td>
                    <td><?= e($r['branch_code']) ?></td>
                    <td><?= e($r['employee'] ?? '—') ?></td>
                    <td class="right num<?= $isCn ? ' neg' : '' ?>"><?= e(rupees(\App\Core\Money::fromDb($r['taxable_value']), 2)) ?></td>
                    <td class="right num<?= $isCn ? ' neg' : '' ?>"><?= e(rupees(\App\Core\Money::fromDb($r['total_value']), 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($self(['w' => $window, 'page' => $page - 1]) . '#documents') ?>">Previous</a><?php endif; ?>
                <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($self(['w' => $window, 'page' => $page + 1]) . '#documents') ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$scripts = [];
require dirname(__DIR__) . '/layouts/app.php';
