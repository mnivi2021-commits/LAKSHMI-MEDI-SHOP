<?php
/** @var \App\Modules\Dashboard\DashboardContext $ctx */
/** @var array<string, \App\Core\DateRange|null> $windows */
/** @var array<int, string> $fyOptions */
/** @var array<string, string> $months */
/** @var array<int, string> $branches */
/** @var array<int, string> $employees */
/** @var array<string, string> $addTypes */
/** @var array<string, ?string> $freshness */
/** @var DateTimeImmutable $today */
/** @var list<array<string, mixed>> $allFys */
/** @var int $currentFyId */
use App\Core\Csrf;

$dashView ??= 'bills';
$isBranch = $dashView === 'branch';
$f = $ctx->filters;
$asOn = $ctx->asOn->format('d-m-Y');
$windows ??= $ctx->windows();
$addTypes ??= [];
$prev = $windows['fy_to_previous_day'];
$mtdPrev = $windows['month_to_previous_day'];
$monthName = $ctx->asOn->format('F');
$dash = '—';
$label = static fn (?\App\Core\DateRange $r): string => $r ? $r->label() : 'No days yet in this period';
$activeFilters = array_filter([$f['month'], $f['branch_id'], $f['employee_id'], $f['customer_id'], $f['product_id']], static fn ($v) => $v !== null);

ob_start();
?>
<div class="page-head dash-head">
    <div>
        <p class="eyebrow">Management dashboard</p>
        <h1><?= $isBranch ? 'Branch Performance' : 'Dashboard · bill-wise detail' ?></h1>
        <p class="muted asof">
            As on <strong><?= e($asOn) ?></strong> · <?= e($ctx->fyRow['label']) ?>
            <?php if ($ctx->isLive): ?><span class="badge badge-ok" title="Includes today's entries so far">Live</span>
            <?php else: ?><span class="badge badge-muted" title="A closed period: figures will not change">Closed period</span><?php endif; ?>
        </p>
    </div>
    <?php if ($isBranch && ($canEntry ?? false)): ?>
        <a class="btn btn-primary btn-lg" href="<?= e(url('entry') . ($f['branch_id'] ? '?branch=' . $f['branch_id'] : '')) ?>">+ ADD</a>
    <?php elseif (!$isBranch && $addTypes): ?>
        <button type="button" class="btn btn-primary btn-lg" data-drawer-open="quick-add" aria-controls="quick-add">+ ADD</button>
    <?php endif; ?>
</div>

<nav class="tabs" aria-label="Dashboard view">
    <a href="<?= e(url('/') . '?' . $ctx->query(['customer' => null, 'product' => null])) ?>" class="<?= $isBranch ? 'active' : '' ?>">Branch performance</a>
    <a href="<?= e(url('/') . '?' . $ctx->query(['view' => 'bills'])) ?>" class="<?= $isBranch ? '' : 'active' ?>">Bill-wise detail</a>
    <?php if (\App\Core\Gate::allowsAny(['targets.view', 'targets.add'])): ?><a href="<?= e(url('targets')) ?>">Targets</a><?php endif; ?>
</nav>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php foreach ($ctx->notices as $n): ?><div class="alert alert-info"><?= e($n) ?></div><?php endforeach; ?>

<!-- Filters (GET, so every view is a shareable, bookmarkable link) -->
<form method="get" action="<?= e(url('/')) ?>" class="card dash-filters<?= $isBranch ? ' dash-filters-4' : '' ?>" aria-label="Dashboard filters">
    <label class="field">
        <span>Financial year</span>
        <select name="fy" data-autosubmit data-reset="month">
            <?php foreach ($fyOptions as $id => $lbl): ?>
                <option value="<?= e($id) ?>"<?= $id === $f['fy_id'] ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Month</span>
        <select name="month" data-autosubmit>
            <option value="">Whole year</option>
            <?php foreach ($months as $val => $lbl): ?>
                <option value="<?= e($val) ?>"<?= $val === $f['month'] ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Branch</span>
        <select name="branch" data-autosubmit data-reset="employee">
            <?php if (count($branches) !== 1): ?><option value="">All branches</option><?php endif; ?>
            <?php foreach ($branches as $id => $lbl): ?>
                <option value="<?= e($id) ?>"<?= $id === $f['branch_id'] ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Sales employee</span>
        <select name="employee" data-autosubmit>
            <?php if (count($employees) !== 1): ?><option value="">All employees</option><?php endif; ?>
            <?php foreach ($employees as $id => $lbl): ?>
                <option value="<?= e($id) ?>"<?= $id === $f['employee_id'] ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if (!$isBranch): ?>
    <input type="hidden" name="view" value="bills">
    <div class="field">
        <span>Customer</span>
        <div class="lookup" data-lookup="customers">
            <input type="text" class="lookup-input" value="<?= e($ctx->customerLabel ?? '') ?>" placeholder="All customers" autocomplete="off" aria-label="Customer filter">
            <input type="hidden" name="customer" value="<?= e($f['customer_id'] ?? '') ?>">
            <ul class="lookup-list" role="listbox" hidden></ul>
        </div>
    </div>
    <div class="field">
        <span>Product</span>
        <div class="lookup" data-lookup="products">
            <input type="text" class="lookup-input" value="<?= e($ctx->productLabel ?? '') ?>" placeholder="All products" autocomplete="off" aria-label="Product filter">
            <input type="hidden" name="product" value="<?= e($f['product_id'] ?? '') ?>">
            <ul class="lookup-list" role="listbox" hidden></ul>
        </div>
    </div>
    <?php endif; ?>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($activeFilters): ?><a class="btn" href="<?= e(url('/') . '?fy=' . $f['fy_id'] . ($isBranch ? '' : '&view=bills')) ?>">Reset</a><?php endif; ?>
    </div>
</form>

<?php if ($isBranch): ?>
<?php require __DIR__ . '/_branch_performance.php'; ?>
<?php if ($mail !== null) { require __DIR__ . '/_mail_section.php'; } ?>
<?php else: ?>

<!-- Section A: KPI cards. Figures are built in Phases 6-8; the periods are live now. -->
<section aria-labelledby="sec-a">
    <h2 id="sec-a" class="section-title">Performance</h2>
    <div class="kpi-grid">
        <?php require __DIR__ . '/_sales_card.php'; ?>
        <?php require __DIR__ . '/_collection_card.php'; ?>
        <?php require __DIR__ . '/_pending_card.php'; ?>
    </div>
</section>

<?php if ($mail !== null) { require __DIR__ . '/_mail_section.php'; } ?>

<?php require __DIR__ . '/_rep_section.php'; ?>
<?php require __DIR__ . '/_customer_section.php'; ?>
<?php require __DIR__ . '/_product_section.php'; ?>

<section class="card freshness" aria-label="Data freshness">
    <h2 class="section-title">Latest entries in your view</h2>
    <ul>
        <?php foreach ($freshness as $what => $date): ?>
            <li><span class="muted small"><?= e($what) ?></span><strong><?= $date ? e(date('d-m-Y', strtotime($date))) : e($dash) ?></strong></li>
        <?php endforeach; ?>
    </ul>
</section>

<?php endif; ?>

<?php if (!$isBranch && $addTypes): ?>
<!-- Quick ADD drawer -->
<div class="drawer-backdrop" data-drawer-close hidden></div>
<aside id="quick-add" class="drawer" role="dialog" aria-modal="true" aria-labelledby="quick-add-title" hidden>
    <header class="drawer-head">
        <h2 id="quick-add-title">Add entry</h2>
        <button type="button" class="btn btn-sm" data-drawer-close aria-label="Close">Close</button>
    </header>
    <nav class="tabs drawer-tabs" role="tablist">
        <?php $first = true; foreach ($addTypes as $type => $lbl): ?>
            <button type="button" role="tab" class="<?= $first ? 'active' : '' ?>" data-tab="<?= e($type) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= e($lbl) ?></button>
        <?php $first = false; endforeach; ?>
    </nav>
    <div class="drawer-body">
        <div class="alert alert-success" data-qa-success hidden></div>
        <?php
        $todayIso = $today->format('Y-m-d');
        $customerField = static function (): void { ?>
            <div class="field">
                <span>Customer *</span>
                <div class="lookup" data-lookup="customers">
                    <input type="text" class="lookup-input" placeholder="Search name, code or mobile" autocomplete="off" aria-label="Customer">
                    <input type="hidden" name="customer_id">
                    <ul class="lookup-list" role="listbox" hidden></ul>
                </div>
                <span class="field-error" data-error-for="customer_id"></span>
            </div>
        <?php };
        $productField = static function (): void { ?>
            <div class="field">
                <span>Product *</span>
                <div class="lookup" data-lookup="products">
                    <input type="text" class="lookup-input" placeholder="Search product" autocomplete="off" aria-label="Product">
                    <input type="hidden" name="product_id">
                    <ul class="lookup-list" role="listbox" hidden></ul>
                </div>
                <span class="field-error" data-error-for="product_id"></span>
            </div>
        <?php };
        $employeeField = static function (bool $required) use ($employees): void { ?>
            <label class="field">
                <span>Sales employee<?= $required ? ' *' : '' ?></span>
                <select name="employee_id">
                    <option value=""><?= $required ? 'Choose employee' : "Customer's employee (default)" ?></option>
                    <?php foreach ($employees as $id => $lbl): ?><option value="<?= e($id) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
                </select>
                <span class="field-error" data-error-for="employee_id"></span>
            </label>
        <?php };
        $text = static function (string $name, string $label, string $type = 'text', string $extra = ''): void { ?>
            <label class="field">
                <span><?= e($label) ?></span>
                <input type="<?= e($type) ?>" name="<?= e($name) ?>" <?= $extra ?>>
                <span class="field-error" data-error-for="<?= e($name) ?>"></span>
            </label>
        <?php };
        $money = static function (string $name, string $label, bool $required = true) use ($text): void {
            $text($name, $label . ($required ? ' *' : '') . ' (₹)', 'text', 'inputmode="decimal" autocomplete="off" placeholder="0.00"' . ($required ? ' required' : ''));
        };
        $date = static function (string $name, string $label, bool $required = true, bool $maxToday = true) use ($text, $todayIso): void {
            $text($name, $label . ($required ? ' *' : ''), 'date', ($required ? 'required value="' . $todayIso . '"' : '') . ($maxToday ? ' max="' . $todayIso . '"' : ''));
        };
        ?>

        <?php if (isset($addTypes['target'])): ?>
        <form class="qa-form" data-quick-add="target" action="<?= e(url('dashboard/add/target')) ?>" novalidate>
            <?= Csrf::field() ?>
            <p class="muted small">Targets are stored per month. Choose "All 12 months" to enter a yearly amount that is split exactly across the year.</p>
            <?php $employeeField(true); ?>
            <label class="field">
                <span>Financial year *</span>
                <select name="fy_id" data-fy-select>
                    <?php foreach ($allFys as $fy): ?>
                        <option value="<?= e($fy['id']) ?>" data-start="<?= e($fy['start_date']) ?>"<?= (int) $fy['id'] === $currentFyId ? ' selected' : '' ?>><?= e($fy['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-error" data-error-for="fy_id"></span>
            </label>
            <label class="field">
                <span>Target month *</span>
                <select name="month" data-month-select data-default="<?= e($ctx->asOn->format('Y-m')) ?>"></select>
                <span class="field-error" data-error-for="month"></span>
            </label>
            <?php $money('sales_target', 'Sales target', false); $money('collection_target', 'Collection target', false); ?>
            <button type="submit" class="btn btn-primary btn-block">Save target</button>
        </form>
        <?php endif; ?>

        <?php if (isset($addTypes['sale'])): ?>
        <form class="qa-form" data-quick-add="sale" action="<?= e(url('dashboard/add/sale')) ?>" novalidate hidden>
            <?= Csrf::field() ?>
            <?php $text('invoice_no', 'Invoice number *', 'text', 'maxlength="40" required'); $date('invoice_date', 'Invoice date'); ?>
            <?php $customerField(); $employeeField(false); $productField(); ?>
            <?php $text('quantity', 'Quantity *', 'text', 'inputmode="decimal" required'); $money('taxable_amount', 'Taxable value (before GST)'); ?>
            <?php $text('gst_rate', 'GST %', 'text', 'inputmode="decimal" placeholder="Product rate" data-gst-field'); $date('due_date', 'Due date', false, false); ?>
            <p class="muted small">Leave due date empty to use the customer's credit days.</p>
            <button type="submit" class="btn btn-primary btn-block">Save sale</button>
        </form>
        <?php endif; ?>

        <?php if (isset($addTypes['collection'])): ?>
        <form class="qa-form" data-quick-add="collection" action="<?= e(url('dashboard/add/collection')) ?>" novalidate hidden>
            <?= Csrf::field() ?>
            <?php $text('receipt_no', 'Receipt number *', 'text', 'maxlength="40" required'); $date('receipt_date', 'Receipt date'); ?>
            <?php $customerField(); $employeeField(false); $money('amount', 'Amount received'); ?>
            <label class="field">
                <span>Payment mode *</span>
                <select name="payment_mode">
                    <?php foreach (['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'dd' => 'Demand draft', 'other' => 'Other'] as $v => $l): ?>
                        <option value="<?= e($v) ?>"><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-error" data-error-for="payment_mode"></span>
            </label>
            <?php $text('reference_no', 'UTR / cheque number', 'text', 'maxlength="60"'); ?>
            <label class="check">
                <input type="hidden" name="auto_allocate" value="0">
                <input type="checkbox" name="auto_allocate" value="1" checked>
                Adjust against the customer's oldest open bills
            </label>
            <button type="submit" class="btn btn-primary btn-block">Save collection</button>
        </form>
        <?php endif; ?>

        <?php if (isset($addTypes['pending_order'])): ?>
        <form class="qa-form" data-quick-add="pending_order" action="<?= e(url('dashboard/add/pending_order')) ?>" novalidate hidden>
            <?= Csrf::field() ?>
            <?php $text('order_no', 'Order number *', 'text', 'maxlength="40" required'); $date('order_date', 'Order date'); ?>
            <?php $customerField(); $employeeField(false); $productField(); ?>
            <?php $text('order_qty', 'Order quantity *', 'text', 'inputmode="decimal" required'); $money('order_value', 'Order value'); ?>
            <?php $text('supplied_qty', 'Supplied quantity', 'text', 'inputmode="decimal" placeholder="0"'); $money('supplied_value', 'Supplied value', false); ?>
            <?php $date('expected_delivery_date', 'Expected delivery', false, false); $text('remarks', 'Remarks', 'text', 'maxlength="255"'); ?>
            <button type="submit" class="btn btn-primary btn-block">Save order</button>
        </form>
        <?php endif; ?>

        <?php if (isset($addTypes['sample'])): ?>
        <form class="qa-form" data-quick-add="sample" action="<?= e(url('dashboard/add/sample')) ?>" novalidate hidden>
            <?= Csrf::field() ?>
            <?php $text('document_no', 'Sample number *', 'text', 'maxlength="40" required'); $date('document_date', 'Sample date'); ?>
            <?php $customerField(); $employeeField(false); $productField(); ?>
            <?php $text('quantity', 'Quantity *', 'text', 'inputmode="decimal" required'); $money('sample_value', 'Sample value', false); ?>
            <label class="field">
                <span>Supply status</span>
                <select name="supply_status">
                    <option value="not_supplied">Not supplied yet</option>
                    <option value="partially_supplied">Partially supplied</option>
                    <option value="supplied">Supplied</option>
                </select>
                <span class="field-error" data-error-for="supply_status"></span>
            </label>
            <?php $text('remarks', 'Remarks', 'text', 'maxlength="255"'); ?>
            <button type="submit" class="btn btn-primary btn-block">Save sample</button>
        </form>
        <?php endif; ?>

        <?php if (isset($addTypes['dc'])): ?>
        <form class="qa-form" data-quick-add="dc" action="<?= e(url('dashboard/add/dc')) ?>" novalidate hidden>
            <?= Csrf::field() ?>
            <?php $text('dc_no', 'DC number *', 'text', 'maxlength="40" required'); $date('dc_date', 'DC date'); ?>
            <?php $customerField(); $employeeField(false); $productField(); ?>
            <?php $text('quantity', 'Quantity *', 'text', 'inputmode="decimal" required'); $money('dc_value', 'DC value', false); ?>
            <?php $text('remarks', 'Remarks', 'text', 'maxlength="255"'); ?>
            <button type="submit" class="btn btn-primary btn-block">Save DC</button>
        </form>
        <?php endif; ?>
    </div>
</aside>
<?php endif; ?>
<?php
$content = ob_get_clean();
$scripts = ['dashboard.js'];
require dirname(__DIR__) . '/layouts/app.php';
