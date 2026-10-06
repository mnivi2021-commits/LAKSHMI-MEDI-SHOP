<?php
/**
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<string, mixed> $c
 * @var list<array<string, mixed>> $monthly
 * @var list<array<string, mixed>> $byBranch
 * @var list<array<string, mixed>> $byEmployee
 * @var array{rows: list<array<string, mixed>>, total: int, count: int} $overdue
 * @var string $window
 * @var \App\Core\DateRange|null $range
 * @var array{rows: list<array<string, mixed>>, total: int, count: int} $list
 * @var int $page
 * @var int $pages
 * @var bool $canExport
 * @var string $billSource
 */
use App\Core\Money;
use App\Core\View;
use App\Modules\Dashboard\CollectionController;

$back = url('/') . '?' . $ctx->query();
$self = static fn (array $o): string => url('dashboard/collection') . '?' . $ctx->query($o);
$windowTotals = ['month' => $c['total'], 'prev' => $c['previous_period'], 'today' => $c['today'], 'fy' => $c['fy_to_date']];
$modes = ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'dd' => 'DD', 'other' => 'Other'];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e($back) ?>">Dashboard</a> / Step A2</p>
        <h1>Payment Collection</h1>
        <p class="muted asof">As on <strong><?= e($ctx->asOn->format('d-m-Y')) ?></strong> · <?= e($c['fy_label']) ?> · received &amp; cleared receipts (bounced / cancelled excluded)</p>
    </div>
    <a class="btn" href="<?= e($back) ?>">← Back to dashboard</a>
</div>
<?php foreach ($ctx->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<?php if (!$c['applies']): ?>
    <div class="card"><p><?= e($c['not_applicable_note']) ?></p></div>
<?php else: ?>
<section class="stat-grid" aria-label="Summary">
    <div class="card stat"><span>Previous period (<?= e($c['month_name']) ?>)</span><strong><?= e(rupees($c['previous_period'])) ?></strong><small class="muted"><?= e($c['periods']['month_to_previous_day'] ?? 'First day of month') ?></small></div>
    <div class="card stat"><span><?= $c['is_live'] ? "Today's collection" : 'As-on day' ?></span><strong><?= e(rupees($c['today'])) ?></strong><small class="muted"><?= e($c['periods']['today']) ?> · <?= e($c['payments_today']) ?> payment(s)</small></div>
    <div class="card stat"><span>Total collection (month)</span><strong><?= e(rupees($c['total'])) ?></strong><small class="muted"><?= e($c['customers']) ?> customers · <?= e($c['payments']) ?> payments</small></div>
    <div class="card stat"><span>Collection target / %</span><strong><?= $c['collection_target'] === null ? 'n/a' : e(rupees($c['collection_target'])) ?></strong>
        <small class="muted"><?= $c['collection_pct'] === null ? e($c['target_note'] ?? '') : e(number_format($c['collection_pct'], 1)) . '% achieved · pending ' . e(rupees($c['collection_pending'])) ?></small></div>
    <div class="card stat"><span>FY to date collection</span><strong><?= e(rupees($c['fy_to_date'])) ?></strong><small class="muted"><?= e($c['periods']['fy_to_date']) ?></small></div>
    <div class="card stat"><span>Overdue collection</span><strong class="bad"><?= e(rupees($c['overdue'])) ?></strong><small class="muted"><?= e($c['overdue_bills']) ?> bills · <?= e($c['overdue_customers']) ?> customers past due date</small></div>
    <div class="card stat"><span>Total outstanding</span><strong><?= e(rupees($c['outstanding'])) ?></strong><small class="muted"><?= $billSource === 'imported' ? 'From the latest uploaded statement' : 'Invoices − adjusted receipts − credit notes' ?></small></div>
    <div class="card stat"><span>Received on account (month)</span><strong><?= e(rupees($c['unallocated'])) ?></strong><small class="muted">Not yet adjusted against a bill</small></div>
</section>

<section class="card">
    <h2>Month-wise collection against collection target</h2>
    <?= View::render('charts/target_vs_sales', ['monthly' => $monthly, 'showTarget' => $c['collection_target'] !== null, 'barLabel' => 'Collection', 'tickLabel' => 'Collection target']) ?>
</section>

<div class="split">
    <?php foreach (['Branch' => $byBranch, 'Sales employee' => $byEmployee] as $title => $rows): ?>
    <section class="card table-card">
        <h2>By <?= e(strtolower($title)) ?></h2>
        <?php if ($rows === []): ?><p class="empty">No collections in this view yet.</p><?php else: ?>
        <div class="table-scroll"><table class="table compact">
            <thead><tr><th><?= e($title) ?></th><th class="right"><?= e($c['month_name']) ?></th><th class="right"><?= $c['is_live'] ? 'Today' : 'As-on day' ?></th><th class="right">Target</th><th class="right">%</th><th class="right">FY to date</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['label']) ?></td><td class="right num"><?= e(rupees($r['month_to_date'])) ?></td><td class="right num"><?= e(rupees($r['on_day'])) ?></td>
                    <td class="right num"><?= $r['target'] === null ? '—' : e(rupees($r['target'])) ?></td><td class="right num"><?= $r['pct'] === null ? '—' : e(number_format($r['pct'], 1)) . '%' ?></td>
                    <td class="right num"><?= e(rupees($r['fy_to_date'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<section class="card table-card" id="overdue">
    <h2>Overdue bills (oldest first)</h2>
    <p class="padded small"><?= e($overdue['count']) ?> bill(s) past due date · total <strong><?= e(rupees($overdue['total'], 2)) ?></strong>
        <?php if ($overdue['total'] === $c['overdue']): ?><span class="badge badge-ok">matches dashboard</span><?php else: ?><span class="badge badge-fail">does not match dashboard</span><?php endif; ?>
        <?= $overdue['count'] > 20 ? ' · showing the 20 oldest; full list in 90 / 150 Days (Phase 10) and Reports' : '' ?></p>
    <?php if ($overdue['rows']): ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Invoice</th><th>Invoice date</th><th>Due date</th><th class="right">Days overdue</th><th>Customer</th><th>Branch</th><th>Employee</th><th class="right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($overdue['rows'] as $r): ?>
            <tr><td class="nowrap"><?= e($r['invoice_no']) ?></td><td class="nowrap"><?= e(date('d-m-Y', strtotime($r['invoice_date']))) ?></td>
                <td class="nowrap"><?= e(date('d-m-Y', strtotime($r['due_date']))) ?></td><td class="right num"><?= e($r['days_overdue']) ?></td>
                <td><?= e($r['customer']) ?> <span class="muted small"><?= e($r['customer_code']) ?></span></td><td><?= e($r['branch_code']) ?></td><td><?= e($r['employee'] ?? '—') ?></td>
                <td class="right num"><?= e(rupees(Money::fromDb($r['balance']), 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card table-card" id="receipts">
    <div class="matrix-head">
        <h2>Receipts behind the figures</h2>
        <?php if ($canExport && $range): ?><a class="btn btn-sm" href="<?= e(url('dashboard/collection/export') . '?' . $ctx->query(['w' => $window])) ?>">Export CSV</a><?php endif; ?>
    </div>
    <nav class="tabs padded-x" aria-label="Period">
        <?php foreach (CollectionController::WINDOWS as $key => [$lbl]): ?>
            <a href="<?= e($self(['w' => $key]) . '#receipts') ?>" class="<?= $key === $window ? 'active' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($range === null): ?>
        <p class="empty">There are no days in this period yet (as-on date is the 1st of the month).</p>
    <?php else: ?>
        <p class="padded small"><?= e($range->label()) ?> · <?= e($list['count']) ?> receipt(s) · total <strong><?= e(rupees($list['total'], 2)) ?></strong>
            <?php if ($list['total'] === $windowTotals[$window]): ?><span class="badge badge-ok">matches dashboard</span><?php else: ?><span class="badge badge-fail">does not match dashboard</span><?php endif; ?></p>
        <?php if ($list['rows'] === []): ?><p class="empty">No receipts in this period.</p><?php else: ?>
        <div class="table-scroll"><table class="table compact">
            <thead><tr><th>Date</th><th>Receipt</th><th>Customer</th><th>Branch</th><th>Employee</th><th>Mode</th><th>Reference</th><th class="right">Amount</th><th class="right">On account</th></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $r): ?>
                <tr><td class="nowrap"><?= e(date('d-m-Y', strtotime($r['receipt_date']))) ?></td>
                    <td class="nowrap"><?= e($r['receipt_no']) ?><?= $r['status'] === 'received' ? ' <span class="badge badge-warn" title="Cheque / DD not yet cleared">uncleared</span>' : '' ?></td>
                    <td><?= e($r['customer']) ?> <span class="muted small"><?= e($r['customer_code']) ?></span></td><td><?= e($r['branch_code']) ?></td><td><?= e($r['employee'] ?? '—') ?></td>
                    <td><?= e($modes[$r['payment_mode']] ?? $r['payment_mode']) ?></td><td class="small"><?= e($r['reference_no'] ?? '') ?></td>
                    <td class="right num"><?= e(rupees(Money::fromDb($r['amount']), 2)) ?></td>
                    <td class="right num"><?= Money::fromDb($r['unallocated_amount']) > 0 ? e(rupees(Money::fromDb($r['unallocated_amount']), 2)) : '—' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($pages > 1): ?>
            <nav class="pagination"><?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($self(['w' => $window, 'page' => $page - 1]) . '#receipts') ?>">Previous</a><?php endif; ?>
                <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($self(['w' => $window, 'page' => $page + 1]) . '#receipts') ?>">Next</a><?php endif; ?></nav>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
