<?php
/** @var array<int, string> $employees */
/** @var int|null $employee */
/** @var string $list */
/** @var array<string, string> $lists */
/** @var string $from */
/** @var string $to */
/** @var string $today */
/** @var string $company */
/** @var list<array<string, mixed>> $rows */
/** @var list<array<string, mixed>> $customers */
/** @var array<string, mixed>|null $customer */
/** @var list<array<string, mixed>> $history */
/** @var array<string, string> $errors */
/** @var bool $canSms */
/** @var bool $canRecord */
use App\Core\Csrf;
use App\Core\Money;
use App\Modules\Followup\FollowupController as F;

$d = static fn (?string $v): string => $v ? date('d-m-Y', strtotime($v)) : '—';
$m = static fn ($v, int $dec = 0): string => rupees($v === null ? null : Money::fromDb($v), $dec);
$qty = static fn ($v): string => rtrim(rtrim((string) $v, '0'), '.');
$link = static fn (array $q): string => url('followup') . '?' . http_build_query(array_filter($q + ['employee' => $employee], static fn ($v) => $v !== null && $v !== ''));
$overdue = static fn (?string $due): bool => $due !== null && $due < $today;
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$total = 0;
ob_start();
?>
<div class="page-head no-print">
    <div>
        <p class="eyebrow">Dashboard</p>
        <h1>Follow up</h1>
        <p class="muted small">Choose a sales employee, then what to follow up.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('followup')) ?>" class="card fu-filters no-print" aria-label="Follow up filters">
    <label class="field">
        <span>Sales employee</span>
        <select name="employee" data-autosubmit>
            <?php if (count($employees) !== 1): ?><option value="">— choose —</option><?php endif; ?>
            <?php foreach ($employees as $id => $lbl): ?>
                <option value="<?= e($id) ?>"<?= $id === $employee ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Follow up</span>
        <select name="list" data-autosubmit>
            <?php foreach ($lists as $k => $lbl): ?>
                <option value="<?= e($k) ?>"<?= $k === $list ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($list === 'dispatch'): ?>
        <label class="field"><span>From</span><input type="date" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>"></label>
        <label class="field"><span>To</span><input type="date" name="to" value="<?= e($to) ?>" max="<?= e($today) ?>"></label>
    <?php endif; ?>
    <?php if ($list === 'payment' && $employee !== null): ?>
        <label class="field fu-customer">
            <span>Customer (<?= count($customers) ?> with dues)</span>
            <select name="customer" data-autosubmit>
                <option value="">— choose customer —</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= e($c['id']) ?>"<?= $customer && (int) $customer['id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name'] . ' · ' . $c['customer_code'] . ' · ' . rupees(Money::fromDb($c['balance']))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <div class="form-actions"><button type="submit" class="btn">Show</button></div>
</form>

<nav class="tabs no-print" aria-label="Follow up lists">
    <?php foreach ($lists as $k => $lbl): ?>
        <a href="<?= e($link(['list' => $k])) ?>" class="<?= $k === $list ? 'active' : '' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($employee === null): ?>
    <div class="card"><p class="empty">Choose a sales employee to see their follow-ups.</p></div>

<?php elseif ($list === 'payment'): ?>
    <?php if ($customer === null): ?>
        <div class="card">
            <h2 class="card-title">Customers with dues</h2>
            <?php if (!$customers): ?>
                <p class="muted">No dues for this sales employee.</p>
            <?php else: ?>
            <div class="table-scroll"><table class="table compact">
                <thead><tr><th>S.No</th><th>Customer</th><th class="right">Bills</th><th class="right">Due balance</th><th>Oldest due date</th></tr></thead>
                <tbody>
                <?php foreach ($customers as $i => $c): $total += Money::fromDb($c['balance']); ?>
                    <tr><td><?= $i + 1 ?></td>
                        <td><a href="<?= e($link(['list' => 'payment', 'customer' => $c['id']])) ?>"><?= e($c['name']) ?></a> <span class="muted small"><?= e($c['customer_code']) ?></span></td>
                        <td class="right num"><?= e($c['bills']) ?></td>
                        <td class="right num"><?= e($m($c['balance'])) ?></td>
                        <td class="<?= $overdue($c['oldest_due']) ? 'text-bad' : '' ?>"><?= e($d($c['oldest_due'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="total-row"><th colspan="3">Total</th><th class="right num"><?= e(rupees($total)) ?></th><th></th></tr></tfoot>
            </table></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <article class="card doc-sheet fu-statement">
            <header class="doc-head">
                <div>
                    <strong class="doc-company"><?= e($company) ?></strong>
                    <div class="muted small">Payment due statement as on <?= e($d($today)) ?></div>
                </div>
                <div class="doc-to">
                    <strong><?= e($customer['name']) ?></strong> <span class="muted small"><?= e($customer['customer_code']) ?></span>
                    <div class="muted small"><?= e(implode(' · ', array_filter([$customer['mobile'], $customer['email'], $customer['city']]))) ?></div>
                </div>
            </header>
            <div class="table-scroll"><table class="table compact">
                <thead><tr><th>S.No</th><th>Invoice no</th><th>Bill date</th><th>P.O reference</th><th class="right">Invoice value</th><th class="right">Due balance</th><th>Due date</th></tr></thead>
                <tbody>
                <?php $billTotal = 0; foreach ($rows as $i => $r): $total += Money::fromDb($r['balance']); $billTotal += Money::fromDb($r['bill_amount']); ?>
                    <tr><td><?= $i + 1 ?></td><td><?= e($r['invoice_no']) ?></td><td><?= e($d($r['invoice_date'])) ?></td>
                        <td><?= $r['po_ref'] !== '' ? e($r['po_ref']) : '<span class="muted">—</span>' ?></td>
                        <td class="right num"><?= e($m($r['bill_amount'], 2)) ?></td>
                        <td class="right num"><?= e($m($r['balance'], 2)) ?></td>
                        <td class="<?= $overdue($r['due_date']) ? 'text-bad' : '' ?>"><?= e($d($r['due_date'])) ?><?= $overdue($r['due_date']) ? ' <span class="small">(' . (int) $r['overdue_days'] . ' days)</span>' : '' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="total-row"><th colspan="4">Total</th><th class="right num"><?= e(rupees($billTotal, 2)) ?></th><th class="right num"><?= e(rupees($total, 2)) ?></th><th></th></tr></tfoot>
            </table></div>
        </article>

        <div class="card no-print">
            <h2 class="card-title">Follow up this customer</h2>
            <div class="form-actions fu-actions">
                <?php if (!empty($customer['email'])): ?>
                    <a class="btn btn-primary" href="<?= e('mailto:' . rawurlencode($customer['email']) . '?subject=' . rawurlencode('Payment due statement - ' . $company) . '&body=' . rawurlencode(F::statementText($customer, $rows, $company))) ?>">Mail statement</a>
                <?php else: ?>
                    <span class="muted small">No email in the customer master.</span>
                <?php endif; ?>
                <?php if ($canSms && !empty($customer['mobile'])): ?>
                    <a class="btn" href="<?= e(url('sms/send') . '?' . http_build_query(['for' => 'payment', 'id' => $customer['id']])) ?>">Send payment due SMS</a>
                <?php endif; ?>
                <button type="button" class="btn" data-print>Print statement</button>
            </div>
            <?php if ($canRecord): ?>
            <form method="post" action="<?= e(url("followup/payment/{$customer['id']}")) ?>" class="fu-record">
                <?= Csrf::field() ?>
                <fieldset class="req-step">
                    <legend>Record the follow-up (mail / direct)</legend>
                    <div class="choice-row">
                        <?php foreach (F::MODES as $k => $lbl): ?>
                            <label class="choice"><input type="radio" name="mode" value="<?= e($k) ?>"> <?= e($lbl) ?></label>
                        <?php endforeach; ?>
                    </div><?= $err('mode') ?>
                    <div class="req-row mt">
                        <label class="field<?= isset($errors['notes']) ? ' has-error' : '' ?>"><span>What the customer said *</span><textarea name="notes" rows="2" maxlength="1000" placeholder="e.g. Will pay ₹50,000 on Friday by NEFT"></textarea><?= $err('notes') ?></label>
                        <label class="field<?= isset($errors['next_date']) ? ' has-error' : '' ?>"><span>Next follow-up date</span><input type="date" name="next_date" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"><?= $err('next_date') ?></label>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn btn-primary">Save follow-up</button></div>
                </fieldset>
            </form>
            <?php endif; ?>
            <?php if ($history): ?>
                <h3 class="mt">Previous payment follow-ups</h3>
                <div class="table-scroll"><table class="table compact">
                    <thead><tr><th>Date</th><th>How</th><th>Status</th><th>Notes</th><th>By</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr><td><?= e(date('d-m-Y', strtotime($h['followup_at']))) ?></td><td><?= e(F::MODES[$h['followup_type']] ?? ucfirst($h['followup_type'])) ?></td>
                            <td><?= e(ucfirst($h['status'])) ?></td><td><?= e($h['outcome'] ?? $h['notes'] ?? '') ?></td><td><?= e($h['by_name'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="card">
        <h2 class="card-title"><?= e($lists[$list]) ?> · <?= e($employees[$employee]) ?><?= $list === 'dispatch' ? ' · ' . e($d($from)) . ' to ' . e($d($to)) : '' ?></h2>
        <?php if (!$rows): ?>
            <p class="muted">Nothing pending.</p>
        <?php else: ?>
        <div class="table-scroll"><table class="table compact">
            <thead><tr>
                <th>S.No</th><th>Date</th><th>No.</th><th>Customer</th><th>Product</th>
                <?php if (in_array($list, ['sales', 'sample'], true)): ?><th class="right">Qty</th><?php endif; ?>
                <th class="right"><?= $list === 'lead' ? 'Expected value' : ($list === 'sales' ? 'Pending value' : 'Value') ?></th>
                <th><?= match ($list) { 'lead' => 'Next follow-up', 'sales' => 'Delivery due', 'sample' => 'Days out', default => 'Payment due' } ?></th>
                <?php if ($list === 'lead'): ?><th>Status</th><?php elseif ($list !== 'sales'): ?><th><?= $list === 'sample' ? 'Status' : 'Reference' ?></th><?php else: ?><th>PO ref</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $total += Money::fromDb($r['value'] ?? '0'); ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($d($r['date'])) ?></td>
                    <td><?php if ($list === 'lead'): ?><a href="<?= e(url("requests/view/{$r['record_type']}/{$r['id']}")) ?>"><?= e($r['number']) ?></a><?php else: ?><?= e($r['number']) ?><?= isset($r['kind']) ? ' <span class="muted small">' . e($r['kind']) . '</span>' : '' ?><?php endif; ?></td>
                    <td><?= e($r['customer']) ?><?= $r['mobile'] ? '<div class="muted small">' . e($r['mobile']) . '</div>' : '' ?></td>
                    <td><?= e($r['product'] ?? '—') ?></td>
                    <?php if (in_array($list, ['sales', 'sample'], true)): ?><td class="right num"><?= e($qty($r['qty'])) ?> <?= e($r['unit'] ?? '') ?></td><?php endif; ?>
                    <td class="right num"><?= e($m($r['value'])) ?></td>
                    <?php if ($list === 'sample'): ?>
                        <td class="<?= (int) $r['days'] > 30 ? 'text-bad' : '' ?>"><?= e($r['days']) ?> days</td>
                        <td><?= $r['approval'] === 'requested' ? '<span class="badge badge-warn">awaiting approval</span>' : '<span class="badge badge-info">pending</span>' ?></td>
                    <?php else: ?>
                        <td class="<?= $overdue($r['due']) && $list !== 'dispatch' ? 'text-bad' : '' ?>"><?= e($d($r['due'])) ?></td>
                        <td><?= $list === 'lead' ? e(ucwords(str_replace('_', ' ', $r['status']))) . ($r['record_type'] === 'enquiry' ? ' <span class="muted small">enquiry</span>' : '') : e($r['ref'] ?? '') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th colspan="<?= in_array($list, ['sales', 'sample'], true) ? 6 : 5 ?>">Total (<?= count($rows) ?>)</th><th class="right num"><?= e(rupees($total)) ?></th><th colspan="2"></th></tr></tfoot>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
