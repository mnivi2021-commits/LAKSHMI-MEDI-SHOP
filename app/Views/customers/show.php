<?php
/** @var array<string, mixed> $customer */
/** @var int $sales */
/** @var ?string $lastOrder */
/** @var ?string $lastPayment */
/** @var int $outstanding */
/** @var bool $canEdit */
use App\Core\Gate;

$c = $customer;
$statusBadge = ['active' => 'badge-ok', 'inactive' => 'badge-muted', 'blocked' => 'badge-fail'];
$dashLink = static fn (string $path) => url($path) . '?' . http_build_query(['customer' => $c['id']]);
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('customers')) ?>">Customers</a></p>
        <h1><?= e($c['name']) ?></h1>
        <p class="muted small"><?= e($c['customer_code']) ?><?= $c['company_name'] ? ' · ' . e($c['company_name']) : '' ?>
            · <span class="badge <?= e($statusBadge[$c['status']] ?? 'badge-muted') ?>"><?= e(ucfirst($c['status'])) ?></span></p>
    </div>
    <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= e(url("customers/{$c['id']}/edit")) ?>">Edit</a><?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<section class="stat-grid" aria-label="Summary">
    <div class="card stat"><span>Sales (FY to date)</span><strong><?= e(rupees($sales)) ?></strong></div>
    <div class="card stat"><span>Outstanding</span><strong class="<?= $outstanding > 0 ? 'bad' : '' ?>"><?= e(rupees($outstanding)) ?></strong></div>
    <div class="card stat"><span>Last order</span><strong class="small"><?= $lastOrder ? e(date('d-m-Y', strtotime($lastOrder))) : '—' ?></strong></div>
    <div class="card stat"><span>Last payment</span><strong class="small"><?= $lastPayment ? e(date('d-m-Y', strtotime($lastPayment))) : '—' ?></strong></div>
</section>

<div class="split">
    <section class="card">
        <h2>Contact details</h2>
        <dl class="kpi-lines">
            <div><dt>Mobile</dt><dd><?= e($c['mobile'] ?? '—') ?></dd></div>
            <?php if ($c['alternate_mobile']): ?><div><dt>Alternate mobile</dt><dd><?= e($c['alternate_mobile']) ?></dd></div><?php endif; ?>
            <div><dt>Email</dt><dd><?= e($c['email'] ?? '—') ?></dd></div>
            <div><dt>Address</dt><dd><?= e(trim(($c['address'] ?? '') . ', ' . ($c['city'] ?? '') . ' ' . ($c['state'] ?? '') . ' ' . ($c['pincode'] ?? ''), ', ') ?: '—') ?></dd></div>
            <?php if ($c['gstin']): ?><div><dt>GSTIN</dt><dd><?= e($c['gstin']) ?></dd></div><?php endif; ?>
        </dl>
    </section>
    <section class="card">
        <h2>Account</h2>
        <dl class="kpi-lines">
            <div><dt>Credit days</dt><dd><?= e($c['credit_days']) ?></dd></div>
            <div><dt>Credit limit</dt><dd><?= $c['credit_limit'] !== null ? e(rupees(\App\Core\Money::fromDb($c['credit_limit']))) : 'No limit' ?></dd></div>
            <div><dt>SMS</dt><dd><?= $c['sms_opt_out'] ? 'Opted out' : 'Allowed' ?></dd></div>
            <div><dt>Customer since</dt><dd><?= e(date('d-m-Y', strtotime($c['created_at']))) ?></dd></div>
        </dl>
    </section>
</div>

<section class="card">
    <h2>Drill-down</h2>
    <div class="form-actions">
        <?php if (Gate::allows('sales.view')): ?><a class="btn" href="<?= e($dashLink('dashboard/sales')) ?>">Sales</a><?php endif; ?>
        <?php if (Gate::allows('collections.view')): ?><a class="btn" href="<?= e($dashLink('dashboard/collection')) ?>">Collection</a><?php endif; ?>
        <?php if (Gate::allows('pending_orders.view')): ?><a class="btn" href="<?= e($dashLink('dashboard/pending')) ?>">Pending orders</a><?php endif; ?>
        <?php if (Gate::allows('outstanding.view')): ?><a class="btn" href="<?= e($dashLink('dashboard/outstanding')) ?>">Outstanding</a><?php endif; ?>
        <?php if (Gate::allows('dashboard.view')): ?><a class="btn" href="<?= e(url('/') . '?' . http_build_query(['customer' => $c['id']])) ?>">Full dashboard panel</a><?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
