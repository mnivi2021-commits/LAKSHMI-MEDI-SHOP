<?php
/** @var string $type */
/** @var array<string, mixed> $doc */
/** @var list<array<string, mixed>> $lines */
/** @var array<string, mixed>|null $customer */
/** @var string $company */
/** @var string|null $employee */
/** @var string|null $informed */
/** @var string|null $approver */
/** @var bool $canApprove */
/** @var bool $canSms */
use App\Core\Csrf;
use App\Core\Money;
use App\Modules\Requests\RequestController as R;

$number = $doc['lead_number'] ?? $doc['order_no'] ?? $doc['dc_no'] ?? $doc['document_no'];
$date = $doc['order_date'] ?? $doc['dc_date'] ?? $doc['document_date'] ?? substr((string) $doc['created_at'], 0, 10);
$title = match ($type) {
    'lead'    => 'Lead sheet',
    'enquiry' => 'Offer',
    'order'   => 'Order',
    'dc'      => 'Delivery challan request',
    'sample'  => 'Sample request',
};
$total = array_sum(array_map(static fn ($l) => Money::fromDb($l['amount']), $lines));
$custName = $customer['name'] ?? $doc['name'] ?? '';
$mobile = $customer['mobile'] ?? $doc['mobile'] ?? null;
$informedText = !empty($doc['informed_by']) ? ucfirst($doc['informed_by']) . ($informed ? ' - ' . $informed : '') : null;
ob_start();
?>
<div class="page-head no-print">
    <div>
        <p class="eyebrow"><a href="<?= e(url('requests') . '?type=' . $type) ?>">Requests</a> · <?= e(R::TYPES[$type][0]) ?></p>
        <h1><?= e($title) ?> <?= e($number) ?></h1>
    </div>
    <div class="form-actions">
        <button type="button" class="btn" data-print>Print</button>
        <?php if ($canSms && $mobile && in_array($type, ['enquiry', 'order'], true)): ?>
            <a class="btn btn-primary" href="<?= e(url('sms/send') . '?' . http_build_query(['for' => $type, 'id' => $doc['id']])) ?>"><?= $type === 'enquiry' ? 'Send enquiry SMS' : 'Send order SMS' ?></a>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<?php if ($type === 'lead'): ?>
    <div class="alert alert-info no-print">Internal lead sheet - generated only, <strong>not sent to the customer</strong>.</div>
<?php endif; ?>
<?php if ($type === 'sample' && $doc['approval_status'] === 'requested'): ?>
    <div class="alert alert-warning">Waiting for a manager's approval. The sample must not be given out yet.
        <?php if ($canApprove): ?>
            <form method="post" action="<?= e(url("requests/sample/{$doc['id']}/decide")) ?>" class="inline-form mt">
                <?= Csrf::field() ?>
                <input type="text" name="note" maxlength="255" placeholder="Note (optional)" aria-label="Approval note">
                <button type="submit" name="decision" value="approve" class="btn btn-sm btn-primary">Approve</button>
                <button type="submit" name="decision" value="reject" class="btn btn-sm">Reject</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<article class="card doc-sheet">
    <header class="doc-head">
        <div>
            <strong class="doc-company"><?= e($company) ?></strong>
            <div class="muted small"><?= e($title) ?></div>
        </div>
        <dl class="doc-meta">
            <div><dt>No.</dt><dd><?= e($number) ?></dd></div>
            <div><dt>Date</dt><dd><?= e(date('d-m-Y', strtotime((string) $date))) ?></dd></div>
            <?php if ($employee): ?><div><dt>Sales employee</dt><dd><?= e($employee) ?></dd></div><?php endif; ?>
        </dl>
    </header>

    <section class="doc-to">
        <div class="muted small"><?= $type === 'enquiry' ? 'To' : 'Customer' ?></div>
        <strong><?= e($custName) ?></strong><?= !empty($customer['customer_code']) ? ' <span class="muted small">' . e($customer['customer_code']) . '</span>' : ' <span class="badge badge-warn">new customer</span>' ?>
        <?php $co = $customer['company_name'] ?? $doc['company_name'] ?? null; if ($co && $co !== $custName): ?><div><?= e($co) ?></div><?php endif; ?>
        <?php if (!empty($doc['contact_person'])): ?><div>Attn: <?= e($doc['contact_person']) ?></div><?php endif; ?>
        <div class="muted small"><?= e(implode(' · ', array_filter([$mobile, $customer['email'] ?? $doc['email'] ?? null, $customer['city'] ?? null]))) ?></div>
    </section>

    <dl class="doc-facts">
        <?php if ($informedText): ?><div><dt>Informed by</dt><dd><?= e($informedText) ?></dd></div><?php endif; ?>
        <?php if (in_array($type, ['lead', 'enquiry'], true)): ?><div><dt>For</dt><dd><?= $doc['lead_type'] === 'new_product' ? 'New product (existing customer)' : 'New customer' ?></dd></div><?php endif; ?>
        <?php if ($type === 'enquiry' && !empty($doc['valid_until'])): ?><div><dt>Offer valid until</dt><dd><?= e(date('d-m-Y', strtotime($doc['valid_until']))) ?></dd></div><?php endif; ?>
        <?php if ($type === 'enquiry' && $doc['enquiry_source']): ?><div><dt>Enquiry came by</dt><dd><?= e(R::SOURCES[$doc['enquiry_source']] ?? $doc['enquiry_source']) ?></dd></div><?php endif; ?>
        <?php if ($type === 'order'): ?>
            <div><dt>Order came by</dt><dd><?= e(R::ORDER_REFS[$doc['reference_type']] ?? '—') ?></dd></div>
            <?php if ($doc['reference_detail']): ?><div><dt>Reference</dt><dd><?= e($doc['reference_detail']) ?></dd></div><?php endif; ?>
            <?php if ($doc['advance_amount'] !== null): ?><div><dt>Advance received</dt><dd><?= e(rupees(Money::fromDb($doc['advance_amount']))) ?></dd></div><?php endif; ?>
            <?php if ($doc['expected_delivery_date']): ?><div><dt>Expected delivery</dt><dd><?= e(date('d-m-Y', strtotime($doc['expected_delivery_date']))) ?></dd></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($type === 'dc'): ?>
            <div><dt>Approved by</dt><dd><?= e(R::DC_APPROVALS[$doc['approval_type']] ?? '—') ?></dd></div>
            <div><dt>Mail</dt><dd><?= e($doc['approval_reference'] ?? '—') ?></dd></div>
        <?php endif; ?>
        <?php if ($type === 'sample'): ?>
            <div><dt>Sample</dt><dd><?= $doc['sample_type'] === 'returnable' ? 'Returnable' : 'Non-returnable' ?></dd></div>
            <div><dt>Manager approval</dt><dd><?= e(ucfirst($doc['approval_status'])) ?><?= $approver ? ' by ' . e($approver) : '' ?><?= $doc['approved_at'] ? ' on ' . e(date('d-m-Y H:i', strtotime($doc['approved_at']))) : '' ?><?= $doc['approval_note'] ? ' - ' . e($doc['approval_note']) : '' ?></dd></div>
        <?php endif; ?>
    </dl>

    <table class="table compact doc-lines">
        <thead><tr><th>#</th><th>Product</th><th class="right">Quantity</th><?php if (!in_array($type, ['dc', 'sample'], true)): ?><th class="right"><?= $type === 'lead' ? 'Approx. price' : ($type === 'enquiry' ? 'Offer price' : 'Rate') ?></th><?php endif; ?><th class="right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): ?>
            <tr><td><?= $i + 1 ?></td><td><?= e($l['product']) ?><?= $l['product_code'] ? ' <span class="muted small">' . e($l['product_code']) . '</span>' : '' ?></td>
                <td class="right num"><?= e(rtrim(rtrim((string) $l['quantity'], '0'), '.')) ?> <?= e($l['unit'] ?? '') ?></td>
                <?php if (!in_array($type, ['dc', 'sample'], true)): ?><td class="right num"><?= e(rupees(Money::fromDb($l['price']), 2)) ?></td><?php endif; ?>
                <td class="right num"><?= e(rupees(Money::fromDb($l['amount']), 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><th colspan="<?= in_array($type, ['dc', 'sample'], true) ? 3 : 4 ?>">Total</th><th class="right num"><?= e(rupees($total, 2)) ?></th></tr></tfoot>
    </table>
    <?php if (!empty($doc['remarks'])): ?><p class="small"><strong>Remarks:</strong> <?= nl2br(e($doc['remarks'])) ?></p><?php endif; ?>
</article>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
