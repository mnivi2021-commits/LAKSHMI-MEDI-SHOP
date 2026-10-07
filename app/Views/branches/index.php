<?php
/** @var list<array<string, mixed>> $branches */
/** @var array{q: string, status: string} $filters */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Csrf;

$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== ''));
ob_start();
?>
<?php $showList ??= false; ?>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= $showList ? 'Customer Support Pending' : 'Sales person' ?></p>
        <h1><?= $showList ? 'Manage branches' : 'Customer Support Pending' ?></h1>
    </div>
    <div class="form-actions">
        <?php if ($showList): ?>
            <a class="btn" href="<?= e(url('branches')) ?>">&larr; Back</a>
            <?php if ($canExport): ?><a class="btn" href="<?= e(url('branches/export')) ?>">Export CSV</a><?php endif; ?>
            <?php if ($canAdd): ?><a class="btn btn-primary" href="<?= e(url('branches/new')) ?>">Add branch</a><?php endif; ?>
        <?php else: ?>
            <a class="btn" href="<?= e(url('branches') . '?view=list') ?>">Manage branches</a>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<?php if ($showList): ?>
<form method="get" action="<?= e(url('branches')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, code, city, state">
    </label>
    <label class="field">
        <span>Status</span>
        <select name="status">
            <option value="">Any</option>
            <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </label>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('branches')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> branch<?= $total === 1 ? '' : 'es' ?></h2>
    <?php if ($branches === []): ?>
        <p class="empty">No branches match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Branch</th><th>Location</th><th>Contact</th><th>Manager</th><th class="right">Employees</th><th class="right">Customers</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($branches as $b): ?>
            <tr>
                <td><strong><?= e($b['name']) ?></strong><div class="muted small"><?= e($b['branch_code']) ?></div></td>
                <td class="small"><?= e(trim(($b['city'] ?? '') . ($b['state'] ? ', ' . $b['state'] : ''), ', ') ?: '—') ?><?= $b['pincode'] ? '<div class="muted">' . e($b['pincode']) . '</div>' : '' ?></td>
                <td class="small"><?= e($b['contact_number'] ?? '—') ?><?= $b['email'] ? '<div class="muted">' . e($b['email']) . '</div>' : '' ?></td>
                <td class="small"><?= e($b['manager_name'] ?? '—') ?></td>
                <td class="right num"><?= e($b['employee_count']) ?></td>
                <td class="right num"><?= e($b['customer_count']) ?></td>
                <td><?= $b['status'] === 'active' ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-fail">Inactive</span>' ?></td>
                <td class="right">
                    <details class="row-menu">
                        <summary class="btn btn-sm">Actions</summary>
                        <div class="row-menu-panel">
                            <a href="<?= e(url("branches/{$b['id']}/edit")) ?>"><?= $canEdit ? 'Edit' : 'View' ?></a>
                            <?php if ($canEdit): ?>
                                <form method="post" action="<?= e(url("branches/{$b['id']}/status")) ?>"
                                      data-confirm="<?= $b['status'] === 'active' ? 'Disable ' . e($b['name']) . '?' : 'Enable ' . e($b['name']) . '?' ?>">
                                    <?= Csrf::field() ?><button type="submit"><?= $b['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url("branches/{$b['id']}/delete")) ?>" data-confirm="Delete <?= e($b['name']) ?>?">
                                    <?= Csrf::field() ?><button type="submit" class="danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php endif; ?>
<?php
$teamBranches ??= []; $team ??= []; $teamBranch ??= 0; $teamRep ??= 0; $repData ??= null; $repOrders ??= []; $repDcs ??= []; $canReason ??= false; $pcts ??= ['sales' => 80, 'collection' => 60]; $teamMonth ??= date('Y-m-01');
$p = static fn ($v): int => \App\Core\Money::fromDb($v);
?>
<?php if (!$showList): ?>
<section class="card table-card team-card">
    <div class="team-head">
        <h2>Sales team · <?= e(date('F Y', strtotime($teamMonth))) ?></h2>
        <form method="get" action="<?= e(url('branches')) ?>" class="team-filter">
            <label class="field"><span>Sales name</span>
                <select name="rep" data-autosubmit>
                    <option value="">All sales people</option>
                    <?php foreach ($team as $tm): ?><option value="<?= e($tm['id']) ?>"<?= (int) $tm['id'] === $teamRep ? ' selected' : '' ?>><?= e(($tm['short_name'] ?: $tm['name']) . ' - ' . $tm['name']) ?></option><?php endforeach; ?>
                </select></label>
            <noscript><button type="submit" class="btn">Show</button></noscript>
        </form>
    </div>
    <?php if ($repData): $r = $repData; $m = static fn (?int $v): string => $v ? rupees($v) : '—'; ?>
    <div class="rep-boxes">
        <section class="rep-box rep-sales">
            <h3>Sales</h3>
            <dl>
                <div><dt>Sales name</dt><dd><?= e(($r['short_name'] ?: $r['name']) . ' - ' . $r['name']) ?></dd></div>
                <div><dt>Area / branch</dt><dd><?= e(($r['area'] ?: '—') . ' · ' . $r['branch']) ?></dd></div>
                <div><dt>Target (annual)</dt><dd><?= e($m($r['annual_target'])) ?></dd></div>
                <div><dt>Annual sales (this year)</dt><dd><?= e($m($r['annual_sales'])) ?></dd></div>
                <div><dt>Month sales (<?= e($r['month']) ?>)</dt><dd><?= e($m($r['month_sales'])) ?><?= $r['month_target'] ? ' <span class="muted small">of ' . e(rupees($r['month_target'])) . '</span>' : '' ?></dd></div>
            </dl>
        </section>
        <section class="rep-box rep-po">
            <h3>Pending order</h3>
            <dl>
                <div><dt>Non stock</dt><dd><?= e($m($r['po_non_stock'])) ?></dd></div>
                <div><dt>Price issue</dt><dd><?= e($m($r['po_price_issue'])) ?></dd></div>
                <div><dt>Doubt</dt><dd><?= e($m($r['po_doubt'])) ?></dd></div>
                <div class="rep-total"><dt>Total pending</dt><dd><?= e($m(($r['po_non_stock'] ?? 0) + ($r['po_price_issue'] ?? 0) + ($r['po_doubt'] ?? 0))) ?></dd></div>
            </dl>
        </section>
        <section class="rep-box rep-pay">
            <h3>Payment</h3>
            <dl>
                <div><dt>OP O/S (opening outstanding)</dt><dd><?= e($m($r['opening'])) ?></dd></div>
                <div><dt>This month collection</dt><dd><?= e($m($r['month_collection'])) ?></dd></div>
                <div><dt>Collected of OP O/S</dt><dd><?= $r['opening'] ? e(number_format($r['month_collection'] * 100 / $r['opening'], 1)) . '%' : '—' ?></dd></div>
            </dl>
        </section>
        <section class="rep-box rep-sdc">
            <h3>Sample &amp; DC</h3>
            <dl>
                <div><dt>DC with order</dt><dd><?= e($m($r['dc_order'])) ?></dd></div>
                <div><dt>DC mail confirmation</dt><dd><?= e($m($r['dc_mail'])) ?></dd></div>
                <div><dt>DC rep's inform</dt><dd><?= e($m($r['dc_rep_inform'])) ?></dd></div>
                <div><dt>Sample returnable</dt><dd><?= e($m($r['sample_returnable'])) ?></dd></div>
                <div><dt>Sample non-returnable</dt><dd><?= e($m($r['sample_non_returnable'])) ?></dd></div>
            </dl>
        </section>
    </div>

    <?php
    $byCustomer = static function (array $rows): array { $g = []; foreach ($rows as $row) { $g[$row['customer'] . ' (' . $row['customer_code'] . ')'][] = $row; } return $g; };
    $reasons = \App\Modules\Branches\BranchController::REASONS;
    ?>
    <section class="rep-detail" id="rep-orders">
        <h3 class="rep-detail-title po">Pending order details · customer-wise</h3>
        <?php if (!$repOrders): ?><p class="empty">No pending orders.</p><?php else: ?>
        <div class="table-scroll"><table class="table compact">
            <thead><tr><th>Order no</th><th>Date</th><th>P.O ref</th><th>Product</th><th class="right">Pending qty</th><th class="right">Price</th><th class="right">Pending value</th><th class="right">Days</th><th>Reason</th></tr></thead>
            <tbody>
            <?php $tot = 0; $seen = []; foreach ($byCustomer($repOrders) as $cust => $rows): $ct = 0; ?>
                <tr class="cust-row"><td colspan="9"><?= e($cust) ?></td></tr>
                <?php foreach ($rows as $o): $v = \App\Core\Money::fromDb($o['pending_value']); $ct += $v; $tot += $v; $first = !isset($seen[$o['order_id']]); $seen[$o['order_id']] = 1; ?>
                <tr>
                    <td><?= e($o['order_no']) ?></td><td><?= e(date('d-m-Y', strtotime($o['order_date']))) ?></td><td><?= e($o['customer_po_no'] ?: '—') ?></td>
                    <td><?= e($o['product']) ?></td>
                    <td class="right num"><?= e(rtrim(rtrim((string) $o['pending_qty'], '0'), '.')) ?> <?= e($o['unit'] ?? '') ?></td>
                    <td class="right num"><?= e(rupees(\App\Core\Money::fromDb($o['rate']), 2)) ?></td>
                    <td class="right num"><?= e(rupees($v)) ?></td>
                    <td class="right num<?= (int) $o['days'] > 30 ? ' text-bad' : '' ?>"><?= e($o['days']) ?></td>
                    <td><?php if ($first && $canReason): ?>
                        <form method="post" action="<?= e(url("branches/order-reason/{$o['order_id']}")) ?>" class="reason-form">
                            <?= \App\Core\Csrf::field() ?>
                            <select name="reason" data-autosubmit aria-label="Reason for order <?= e($o['order_no']) ?>">
                                <option value="">— choose —</option>
                                <?php foreach ($reasons as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= $o['pending_reason'] === $rk ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                        <?php elseif ($first): ?><?= e($reasons[$o['pending_reason']] ?? '—') ?><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="cust-total"><td colspan="6">Customer total</td><td class="right num"><?= e(rupees($ct)) ?></td><td colspan="2"></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th colspan="6">Total pending orders</th><th class="right num"><?= e(rupees($tot)) ?></th><th colspan="2"></th></tr></tfoot>
        </table></div>
        <?php endif; ?>
    </section>

    <section class="rep-detail" id="rep-dc">
        <h3 class="rep-detail-title dc">Open DC · customer-wise</h3>
        <?php if (!$repDcs): ?><p class="empty">No open DC.</p><?php else: ?>
        <div class="table-scroll"><table class="table compact">
            <thead><tr><th>DC no</th><th>Date</th><th>Product</th><th class="right">Qty</th><th class="right">Value</th><th class="right">Days open</th><th>Approval</th></tr></thead>
            <tbody>
            <?php $tot = 0; foreach ($byCustomer($repDcs) as $cust => $rows): $ct = 0; ?>
                <tr class="cust-row"><td colspan="7"><?= e($cust) ?></td></tr>
                <?php foreach ($rows as $d): $v = \App\Core\Money::fromDb($d['dc_value']); $ct += $v; $tot += $v; ?>
                <tr><td><?= e($d['dc_no']) ?></td><td><?= e(date('d-m-Y', strtotime($d['dc_date']))) ?></td><td><?= e($d['product']) ?></td>
                    <td class="right num"><?= e(rtrim(rtrim((string) $d['quantity'], '0'), '.')) ?> <?= e($d['unit'] ?? '') ?></td>
                    <td class="right num"><?= e(rupees($v)) ?></td>
                    <td class="right num<?= (int) $d['days'] > 30 ? ' text-bad' : '' ?>"><?= e($d['days']) ?></td>
                    <td><?= e(['customer_mail' => 'Customer mail', 'md_approval' => 'M.D approval'][$d['approval_type'] ?? ''] ?? '—') ?></td></tr>
                <?php endforeach; ?>
                <tr class="cust-total"><td colspan="4">Customer total</td><td class="right num"><?= e(rupees($ct)) ?></td><td colspan="2"></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th colspan="4">Total open DC</th><th class="right num"><?= e(rupees($tot)) ?></th><th colspan="2"></th></tr></tfoot>
        </table></div>
        <?php endif; ?>
    </section>
    <?php elseif (!$team): ?><p class="empty">No sales people.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr>
            <th>Name</th><th>Area</th><th>Division</th>
            <th class="right th-sales">Target</th><th class="right th-sales"><?= e($pcts['sales']) ?>%</th>
            <th class="right th-coll">OP outstanding</th><th class="right th-coll"><?= e($pcts['collection']) ?>%</th>
        </tr></thead>
        <tbody>
        <?php $t1 = 0; $t2 = 0; foreach ($team as $m): $tg = $p($m['target']); $op = $p($m['opening']); $t1 += $tg; $t2 += $op; ?>
            <tr>
                <td><b><?= e($m['short_name'] ?: $m['name']) ?></b> <span class="muted small"><?= e($m['name']) ?></span><?= $teamBranch ? '' : '<div class="muted small">' . e($m['branch']) . '</div>' ?></td>
                <td><?= e($m['area'] ?: '—') ?></td>
                <td><?= e($m['divisions'] ?: '—') ?></td>
                <td class="right num"><?= $tg ? e(rupees($tg)) : '—' ?></td>
                <td class="right num sheet-calc-sales"><?= $tg ? e(rupees((int) round($tg * $pcts['sales'] / 100))) : '—' ?></td>
                <td class="right num"><?= $op ? e(rupees($op)) : '—' ?></td>
                <td class="right num sheet-calc-collection"><?= $op ? e(rupees((int) round($op * $pcts['collection'] / 100))) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><th colspan="3">Total</th>
            <th class="right num"><?= e(rupees($t1)) ?></th><th class="right num"><?= e(rupees((int) round($t1 * $pcts['sales'] / 100))) ?></th>
            <th class="right num"><?= e(rupees($t2)) ?></th><th class="right num"><?= e(rupees((int) round($t2 * $pcts['collection'] / 100))) ?></th></tr></tfoot>
    </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
