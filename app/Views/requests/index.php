<?php
/** @var string $type */
/** @var array<string, array{0: string, 1: string, 2: string}> $allowed */
/** @var array<string, string> $errors */
/** @var array<string, mixed> $values */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $employees */
/** @var list<array<string, mixed>> $recent */
/** @var bool $canApprove */
/** @var bool $canNewCustomer */
/** @var string|null $nextNo */
$nextNo ??= null;
use App\Core\Csrf;
use App\Core\Money;
use App\Modules\Requests\RequestController as R;

$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $f): string => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$cls = static fn (string $f): string => isset($errors[$f]) ? ' has-error' : '';
$line = static fn (int $i, string $k): string => (string) ($values['lines'][$i][$k] ?? '');
$isLead = in_array($type, ['lead', 'enquiry'], true);
$needsMaster = !$isLead;
$priceHead = match ($type) { 'lead' => 'Approx. price / unit (₹)', 'enquiry' => 'Offer price / unit (₹)', 'order' => 'Rate / unit (₹)', default => 'Value (₹)' };
$groups = [];
foreach ($allowed as $k => [$label, $group]) {
    $groups[$group][$k] = $label;
}
$statusBadge = ['approved' => 'badge-ok', 'requested' => 'badge-warn', 'rejected' => 'badge-fail', 'open' => 'badge-info', 'pending' => 'badge-info',
                'new' => 'badge-muted', 'quotation' => 'badge-info', 'won' => 'badge-ok', 'lost' => 'badge-fail', 'closed' => 'badge-muted', 'partial' => 'badge-warn'];
$refLabels = R::ORDER_REFS + R::DC_APPROVALS + ['returnable' => 'Returnable', 'non_returnable' => 'Non-returnable'];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Requests</p>
        <h1><?= e(R::TYPES[$type][0]) ?></h1>
        <p class="muted small"><?= e(match ($type) {
            'lead'    => 'Record a new lead. It is generated for the team only - nothing is sent to the customer.',
            'enquiry' => 'A customer enquiry for a new customer or a new product. Saving prepares the offer with your prices.',
            'order'   => 'Record a customer order and how it came (PO, mail, phone call or advance payment).',
            'dc'      => 'Open a delivery challan. Only with the customer\'s mail or the M.D\'s approval mail.',
            'sample'  => 'Request a sample for a customer. A manager must approve it before it is given out.',
        }) ?></p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<nav class="req-nav" aria-label="Request type">
    <?php foreach ($groups as $group => $types): ?>
        <div class="req-group"><span class="req-group-name"><?= e($group) ?></span>
            <?php foreach ($types as $k => $label): ?>
                <a href="<?= e(url('requests') . '?type=' . $k) ?>" class="btn<?= $k === $type ? ' btn-primary' : '' ?>"<?= $k === $type ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</nav>

<form method="post" action="<?= e(url('requests/' . $type)) ?>" class="card form req-form" novalidate>
    <?= Csrf::field() ?>

    <?php
    $sel = static function (string $name, array $options, string $empty) use ($v): string {
        $h = '<select name="' . e($name) . '"><option value="">' . e($empty) . '</option>';
        foreach ($options as $k => $l) {
            $h .= '<option value="' . e($k) . '"' . ($v($name) === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        return $h . '</select>';
    };
    $docName = ['lead' => 'Lead', 'enquiry' => 'Enquiry', 'order' => 'Order', 'dc' => 'DC', 'sample' => 'Sample'][$type];
    $df = ['order' => 'order_date', 'dc' => 'dc_date', 'sample' => 'document_date'][$type] ?? null;
    ?>
    <div class="inv-head">
        <!-- Left: customer (like the "Bill to" block of an invoice) -->
        <section class="inv-box inv-customer" aria-label="Customer">
            <h3 class="inv-title">Customer</h3>
            <div class="inv-fields">
                <label class="field"><span>Customer name</span>
                    <input type="text" id="cust-name" value="<?= e($v('customer_label')) ?>" readonly placeholder="Chosen customer shows here" tabindex="-1">
                    <span class="muted small" id="cust-meta"></span>
                </label>
                <div class="field<?= $cls('customer_id') ?>"><span>Search customer</span>
                    <div class="lookup" data-lookup="customers" data-name-target="cust-name" data-meta-target="cust-meta">
                        <input type="text" class="lookup-input" name="customer_label" value="<?= e($v('customer_label')) ?>" placeholder="Type customer name, code or mobile" autocomplete="off" aria-label="Search customer">
                        <input type="hidden" name="customer_id" value="<?= e($v('customer_id')) ?>">
                        <ul class="lookup-list" role="listbox" hidden></ul>
                    </div><?= $err('customer_id') ?></div>
                <?php if ($isLead): ?><label class="field"><span>Contact person</span><input type="text" name="contact_person" value="<?= e($v('contact_person')) ?>" maxlength="150"></label><?php endif; ?>
            </div>
            <details class="req-new"<?= $v('new_name') !== '' || isset($errors['new_mobile']) || isset($errors['branch_id']) ? ' open' : '' ?>>
                <summary>New customer (not in the master)<?= $needsMaster ? ($canNewCustomer ? ' - will be added to the customer master' : ' - you cannot add customers; ask for it to be added') : '' ?></summary>
                <div class="inv-fields">
                    <label class="field"><span>Customer name</span><input type="text" name="new_name" value="<?= e($v('new_name')) ?>" maxlength="150"></label>
                    <label class="field"><span>Company</span><input type="text" name="new_company" value="<?= e($v('new_company')) ?>" maxlength="150"></label>
                    <label class="field<?= $cls('new_mobile') ?>"><span>Mobile</span><input type="tel" name="new_mobile" value="<?= e($v('new_mobile')) ?>" maxlength="20"><?= $err('new_mobile') ?></label>
                    <label class="field<?= $cls('new_email') ?>"><span>Email</span><input type="email" name="new_email" value="<?= e($v('new_email')) ?>" maxlength="150"><?= $err('new_email') ?></label>
                    <label class="field"><span>City</span><input type="text" name="new_city" value="<?= e($v('new_city')) ?>" maxlength="80"></label>
                    <label class="field<?= $cls('branch_id') ?>"><span>Branch</span><select name="branch_id"><option value="">Choose</option>
                        <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= $v('branch_id') === (string) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select><?= $err('branch_id') ?></label>
                    <label class="field<?= $cls('employee_id') ?>"><span>Sales employee</span><select name="employee_id"><option value="">Choose</option>
                        <?php foreach ($employees as $em): if (!$em['is_sales_rep']) { continue; } ?><option value="<?= e($em['id']) ?>"<?= $v('employee_id') === (string) $em['id'] ? ' selected' : '' ?>><?= e(($em['short_name'] ?: $em['name']) . ' - ' . $em['name']) ?></option><?php endforeach; ?></select><?= $err('employee_id') ?></label>
                </div>
            </details>
        </section>

        <!-- Right: document number, date, reference and other details -->
        <section class="inv-box inv-doc" aria-label="<?= e($docName) ?> details">
            <dl class="inv-meta">
                <div><dt><?= e($docName) ?> no.</dt><dd><?= e($nextNo ?? '—') ?> <span class="muted small">(given on save)</span></dd></div>
                <?php if ($df === null): ?><div><dt>Date</dt><dd><?= e(date('d-m-Y')) ?></dd></div><?php endif; ?>
            </dl>
            <div class="inv-fields">
                <?php if ($df !== null): ?>
                    <label class="field<?= $cls($df) ?>"><span>Date *</span><input type="date" name="<?= e($df) ?>" value="<?= e($v($df) ?: date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"><?= $err($df) ?></label>
                <?php endif; ?>
                <?php if ($type === 'order'): ?>
                    <label class="field<?= $cls('expected_date') ?>"><span>Expected delivery</span><input type="date" name="expected_date" value="<?= e($v('expected_date')) ?>"><?= $err('expected_date') ?></label>
                    <label class="field<?= $cls('reference_type') ?>"><span>Ref: order came by *</span><?= $sel('reference_type', R::ORDER_REFS, 'Choose') ?><?= $err('reference_type') ?></label>
                    <label class="field<?= $cls('reference_detail') ?>"><span>Ref no. (PO no. / mail date / caller)</span><input type="text" name="reference_detail" value="<?= e($v('reference_detail')) ?>" maxlength="255"><?= $err('reference_detail') ?></label>
                    <label class="field<?= $cls('advance_amount') ?>"><span>Advance amount (₹)</span><input type="text" name="advance_amount" value="<?= e($v('advance_amount')) ?>" inputmode="decimal"><?= $err('advance_amount') ?></label>
                <?php elseif ($type === 'enquiry'): ?>
                    <label class="field<?= $cls('enquiry_source') ?>"><span>Ref: enquiry came by *</span><?= $sel('enquiry_source', R::SOURCES, 'Choose') ?><?= $err('enquiry_source') ?></label>
                    <label class="field<?= $cls('lead_type') ?>"><span>Enquiry for *</span><?= $sel('lead_type', ['new_customer' => 'New customer', 'new_product' => 'New product'], 'Choose') ?><?= $err('lead_type') ?></label>
                    <label class="field<?= $cls('valid_until') ?>"><span>Enquiry expiry date *</span><input type="date" name="valid_until" value="<?= e($v('valid_until')) ?>" min="<?= e(date('Y-m-d')) ?>"><?= $err('valid_until') ?></label>
                <?php elseif ($type === 'dc'): ?>
                    <label class="field<?= $cls('approval_type') ?>"><span>Ref: approved by *</span><?= $sel('approval_type', R::DC_APPROVALS, 'Choose') ?><?= $err('approval_type') ?></label>
                    <label class="field<?= $cls('approval_reference') ?>"><span>Mail details * (date, from, subject)</span><input type="text" name="approval_reference" value="<?= e($v('approval_reference')) ?>" maxlength="255" placeholder="e.g. 06-10-2026, purchase@customer.com"><?= $err('approval_reference') ?></label>
                    <label class="field<?= $cls('order_no') ?>"><span>Against order no. (optional)</span><input type="text" name="order_no" value="<?= e($v('order_no')) ?>" maxlength="40"><?= $err('order_no') ?></label>
                <?php elseif ($type === 'sample'): ?>
                    <label class="field<?= $cls('sample_type') ?>"><span>Sample is *</span><?= $sel('sample_type', ['returnable' => 'Returnable', 'non_returnable' => 'Non-returnable'], 'Choose') ?><?= $err('sample_type') ?></label>
                    <p class="muted small">A manager must approve the sample before it is given out<?= $canApprove ? ' (your own request is approved at once)' : '' ?>.</p>
                <?php endif; ?>
                <?php if (in_array($type, ['lead', 'enquiry', 'order'], true)): ?>
                    <label class="field<?= $cls('informed_by') ?>"><span>Informed by *</span><?= $sel('informed_by', ['manager' => 'Manager', 'rep' => 'Rep'], 'Choose') ?><?= $err('informed_by') ?></label>
                    <label class="field<?= $cls('informed_employee_id') ?>"><span>Manager / Rep name</span>
                        <select name="informed_employee_id"><option value="">—</option>
                            <?php foreach ($employees as $em): ?><option value="<?= e($em['id']) ?>"<?= $v('informed_employee_id') === (string) $em['id'] ? ' selected' : '' ?>><?= e(($em['short_name'] ?: $em['name']) . ' - ' . $em['name']) ?></option><?php endforeach; ?>
                        </select><?= $err('informed_employee_id') ?></label>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <fieldset class="req-step">
        <legend>Products</legend>
        <div class="table-scroll">
        <table class="table compact req-lines">
            <?php $valueIsTotal = in_array($type, ['dc', 'sample'], true); ?>
            <thead><tr><th>#</th><th>Product<?= $isLead ? ' (or type it if not in the list)' : '' ?></th><th class="right">Quantity</th><th class="right"><?= e($priceHead) ?></th><?php if (!$valueIsTotal): ?><th class="right">Amount (₹)</th><?php endif; ?></tr></thead>
            <tbody>
            <?php for ($i = 0; $i < R::LINES; $i++): $le = "lines.{$i}"; ?>
                <tr data-line="<?= $valueIsTotal ? 'value' : 'mul' ?>">
                    <td class="muted"><?= $i + 1 ?></td>
                    <td class="<?= isset($errors["{$le}.product"]) ? 'sheet-error' : '' ?>">
                        <div class="lookup" data-lookup="products">
                            <input type="text" class="lookup-input" name="lines[<?= $i ?>][product_label]" value="<?= e($line($i, 'product_label')) ?>" placeholder="Search product" autocomplete="off" aria-label="Product line <?= $i + 1 ?>">
                            <input type="hidden" name="lines[<?= $i ?>][product_id]" value="<?= e($line($i, 'product_id')) ?>">
                            <ul class="lookup-list" role="listbox" hidden></ul>
                        </div>
                        <?php if ($isLead): ?><input type="text" class="req-desc" name="lines[<?= $i ?>][description]" value="<?= e($line($i, 'description')) ?>" maxlength="200" placeholder="…or describe a product not in the list" aria-label="Description line <?= $i + 1 ?>"><?php endif; ?>
                        <?= $err("{$le}.product") ?>
                    </td>
                    <td class="right<?= isset($errors["{$le}.qty"]) ? ' sheet-error' : '' ?>"><input type="text" class="req-num" name="lines[<?= $i ?>][qty]" data-qty value="<?= e($line($i, 'qty')) ?>" inputmode="decimal" aria-label="Quantity line <?= $i + 1 ?>"><?= $err("{$le}.qty") ?></td>
                    <td class="right<?= isset($errors["{$le}.price"]) ? ' sheet-error' : '' ?>"><input type="text" class="req-num" name="lines[<?= $i ?>][price]" data-price value="<?= e($line($i, 'price')) ?>" inputmode="decimal" aria-label="Price line <?= $i + 1 ?>"><?= $err("{$le}.price") ?></td>
                    <?php if (!$valueIsTotal): ?><td class="right num"><output data-line-amount>—</output></td><?php endif; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
            <tfoot><tr class="total-row"><th colspan="<?= $valueIsTotal ? 3 : 4 ?>">Total</th><th class="right num"><output data-lines-total>₹0</output></th></tr></tfoot>
        </table>
        </div>
        <label class="field field-wide"><span>Remarks</span><textarea name="remarks" rows="2" maxlength="500"><?= e($v('remarks')) ?></textarea></label>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= e(match ($type) { 'lead' => 'Generate lead', 'enquiry' => 'Save and prepare offer', 'order' => 'Save order', 'dc' => 'Save DC request', 'sample' => 'Send for approval' }) ?></button>
    </div>
</form>

<section class="card table-card">
    <h2>Recent <?= e(['lead' => 'new leads', 'enquiry' => 'enquiries', 'order' => 'orders', 'dc' => 'DC requests', 'sample' => 'sample requests'][$type]) ?></h2>
    <?php if ($recent === []): ?><p class="empty">None yet.</p><?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>No.</th><th>Date</th><th>Customer</th><th><?= $isLead ? 'Type' : 'Reference' ?></th><th class="right">Value</th><th>Employee</th><th>Status</th><?php if ($type === 'sample' && $canApprove): ?><th class="right">Approval</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><a href="<?= e(url("requests/view/{$type}/{$r['id']}")) ?>"><?= e($r['number']) ?></a></td>
                <td class="small nowrap"><?= e(date('d-m-Y', strtotime((string) $r['date']))) ?></td>
                <td><?= e($r['customer']) ?></td>
                <td class="small"><?= e($isLead ? ($r['lead_type'] === 'new_product' ? 'New product' : 'New customer') : ($refLabels[$r['ref'] ?? ''] ?? '—')) ?></td>
                <td class="right num"><?= $r['value'] !== null ? e(rupees(Money::fromDb($r['value']))) : '—' ?></td>
                <td class="small"><?= e($r['employee'] ?? '—') ?></td>
                <td><span class="badge <?= e($statusBadge[$r['status']] ?? 'badge-muted') ?>"><?= e(ucfirst(str_replace('_', ' ', (string) $r['status']))) ?></span></td>
                <?php if ($type === 'sample' && $canApprove): ?>
                <td class="right">
                    <?php if ($r['status'] === 'requested'): ?>
                        <form method="post" action="<?= e(url("requests/sample/{$r['id']}/decide")) ?>" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-primary">Approve</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-sm">Reject</button>
                        </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
