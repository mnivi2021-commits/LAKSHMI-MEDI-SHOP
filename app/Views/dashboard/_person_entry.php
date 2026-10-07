<?php
/**
 * Daily entry for ONE sales person: left = sales and pipeline, right = collection and outstanding.
 * Included from dashboard/sheet.php (uses its variables).
 *
 * @var array<string, mixed>|null $person
 * @var array<string, int>|null $mtd
 * @var list<array<string, mixed>> $people
 * @var DateTimeImmutable $date
 * @var string $area
 * @var int|null $branch
 * @var array<int, array<string, mixed>> $values
 * @var array<int, array<string, string>> $hints
 * @var array<string, string> $errors
 */
use App\Core\Csrf;
use App\Modules\Dashboard\EntryController;
use App\Modules\Dashboard\Kpi\BranchPerformance;

if ($person === null): ?>
    <div class="card"><p class="empty">Choose a <b>sales employee</b> above<?= $area !== '' ? ' (' . count($people) . ' in ' . e($area) . ')' : '' ?> to enter the day's figures.</p></div>
<?php return; endif;

$id = (int) $person['id'];
$v = $values[$id] ?? [];
$field = static function (string $f, string $label) use ($id, $v, $errors, $hints): string {
    $err = $errors["{$id}.{$f}"] ?? null;
    $count = EntryController::DAY_FIELDS[$f][2] === 'count';
    $isPos = in_array($f, BranchPerformance::POSITIONS, true);
    $hint = $isPos && isset($hints[$id][$f]) ? ' placeholder="' . e($hints[$id][$f]) . '"' : '';
    return '<label class="pe-field' . ($err ? ' has-error' : '') . '"><span>' . e($label) . '</span>'
        . '<input type="text" name="rows[' . $id . '][' . e($f) . ']" value="' . e($v[$f] ?? '') . '" inputmode="' . ($count ? 'numeric' : 'decimal') . '"'
        . ' class="' . ($count ? 'sheet-count' : 'sheet-money') . '"' . $hint . ($err ? ' title="' . e($err) . '"' : '') . '>'
        . ($err ? '<span class="field-error">' . e($err) . '</span>' : '') . '</label>';
};
$pct = static fn (int $done, int $target): string => $target > 0 ? number_format($done * 100 / $target, 1) . '%' : '—';
?>
<form method="post" action="<?= e(url('entry/day')) ?>" class="person-entry" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="date" value="<?= e($date->format('Y-m-d')) ?>">
    <input type="hidden" name="employee" value="<?= e($id) ?>">
    <?php if ($area !== ''): ?><input type="hidden" name="area" value="<?= e($area) ?>"><?php endif; ?>
    <?php if ($branch): ?><input type="hidden" name="branch" value="<?= e($branch) ?>"><?php endif; ?>

    <header class="pe-head card">
        <div>
            <h2><?= e($person['name']) ?> <span class="muted small"><?= e($person['short_name'] ?? '') ?></span></h2>
            <p class="muted small"><?= e($person['branch']) ?><?= $person['area'] ? ' · Area: ' . e($person['area']) : '' ?> · <?= e($date->format('d-m-Y')) ?><?= !empty($v['_exists']) ? ' · <b>saved</b>' : '' ?></p>
        </div>
        <p class="muted small">Blank grey boxes keep the last figure.</p>
    </header>

    <div class="pe-grid">
        <div class="pe-col">
            <fieldset class="pe-box pe-sales">
                <legend>Sales</legend>
                <div class="pe-row"><?= $field('sales_value', 'Total value (₹)') ?><?= $field('sales_bills', 'NOB') ?><?= $field('sales_customers', 'NOC') ?></div>
                <?php if ($mtd): ?><p class="pe-mtd">This month: <b><?= e(rupees($mtd['sales'])) ?></b> · NOB <?= e($mtd['sales_bills']) ?> · NOC <?= e($mtd['sales_customers']) ?> · Target <?= e(rupees($mtd['sales_target'])) ?> · <b><?= e($pct($mtd['sales'], $mtd['sales_target'])) ?></b></p><?php endif; ?>
            </fieldset>
            <fieldset class="pe-box">
                <legend>Pending order</legend>
                <div class="pe-row"><?= $field('po_non_stock', 'Non stock (₹)') ?><?= $field('po_price_issue', 'Price issue (₹)') ?><?= $field('po_doubt', 'Doubt (₹)') ?></div>
            </fieldset>
            <fieldset class="pe-box">
                <legend>Enquiry pending</legend>
                <div class="pe-row"><?= $field('enq_new_customer', 'New customer') ?><?= $field('enq_new_product', 'New product') ?></div>
            </fieldset>
            <fieldset class="pe-box">
                <legend>Lead pending <span class="muted small">(new today)</span></legend>
                <div class="pe-row"><?= $field('lead_new_customer', 'New customer') ?><?= $field('lead_new_product', 'New product') ?></div>
            </fieldset>
            <fieldset class="pe-box">
                <legend>Sample / DC</legend>
                <div class="pe-row"><?= $field('dc_order', 'DC with order (₹)') ?><?= $field('dc_mail', 'DC mail conf. (₹)') ?><?= $field('dc_rep_inform', "DC rep's inform (₹)") ?></div>
                <div class="pe-row"><?= $field('sample_returnable', 'Sample returnable (₹)') ?><?= $field('sample_non_returnable', 'Sample non-return. (₹)') ?></div>
            </fieldset>
        </div>

        <div class="pe-col">
            <fieldset class="pe-box pe-collection">
                <legend>Payment collection</legend>
                <div class="pe-row"><?= $field('collection_value', 'Total value (₹)') ?><?= $field('collection_bills', 'NOB') ?><?= $field('collection_customers', 'NOC') ?></div>
                <?php if ($mtd): ?><p class="pe-mtd">This month: <b><?= e(rupees($mtd['collection'])) ?></b> · NOB <?= e($mtd['collection_bills']) ?> · NOC <?= e($mtd['collection_customers']) ?> · Target <?= e(rupees($mtd['collection_target'])) ?> · <b><?= e($pct($mtd['collection'], $mtd['collection_target'])) ?></b></p><?php endif; ?>
            </fieldset>
            <fieldset class="pe-box pe-os">
                <legend>Outstanding</legend>
                <div class="pe-stack"><?= $field('os_overdue', 'Overdue (₹)') ?><?= $field('os_90', '90 DAYS (₹)') ?><?= $field('os_150', '150 DAYS (₹)') ?></div>
            </fieldset>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save <?= e($person['short_name'] ?: $person['name']) ?></button>
        <a class="btn" href="<?= e(url('/') . ($branch ? '?branch=' . $branch : '')) ?>">Back to dashboard</a>
        <span class="muted small">NOB = number of bills · NOC = number of customers · amounts in ₹ (commas allowed).</span>
    </div>
</form>
