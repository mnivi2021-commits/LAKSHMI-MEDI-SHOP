<?php
/** @var array<string, mixed> $fy */
/** @var list<array<string, mixed>> $divisions */
/** @var list<array<string, mixed>> $areas */
/** @var list<array<string, mixed>> $employees */
/** @var array<string, int> $map */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
use App\Core\Csrf;
use App\Modules\Dashboard\TargetController;

$plain = static fn (int $p): string => $p > 0 ? rtrim(rtrim(number_format($p / 100, 2, '.', ''), '0'), '.') : '';
$cell = static function (string $field, string $key, string $total) use ($map, $old, $errors, $plain): string {
    $value = array_key_exists($field, $old) ? (string) $old[$field] : $plain($map[$key] ?? 0);
    $err = $errors[$field] ?? null;
    return '<td class="sheet-cell' . ($err ? ' sheet-error' : '') . '"><input type="text" name="t[' . e($field) . ']" value="' . e($value) . '" inputmode="decimal" class="sheet-money" data-total="' . e($total) . '"' . ($err ? ' title="' . e($err) . '"' : '') . '></td>';
};
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('targets') . '?fy=' . $fy['id']) ?>">Targets</a></p>
        <h1>Set annual targets · <?= e($fy['label']) ?></h1>
        <p class="muted small"><?= e(TargetController::months($fy)) ?>. Type the annual target in ₹ (commas allowed). The month target is worked out as annual ÷ 12. Leave a box blank for no target.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php if (isset($errors['_'])): ?><div class="alert alert-error"><?= e($errors['_']) ?></div><?php endif; ?>

<form method="post" action="<?= e(url('targets')) ?>" class="tg-edit" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="fy" value="<?= e($fy['id']) ?>">

    <section class="card table-card">
        <h2>1. Division (annual ₹)</h2>
        <div class="table-scroll"><table class="table compact sheet-table">
            <thead><tr><?php foreach ($divisions as $d): ?><th class="right th-sales"><?= e($d['name']) ?></th><?php endforeach; ?><th class="right">All divisions</th></tr></thead>
            <tbody><tr><?php foreach ($divisions as $d): ?><?= $cell("d{$d['id']}", "division:{$d['id']}:0:0", 'div') ?><?php endforeach; ?>
                <td class="right num"><b><output data-total-of="div"></output></b></td></tr></tbody>
        </table></div>
    </section>

    <section class="card table-card">
        <h2>2. Area-wise (annual ₹)</h2>
        <div class="table-scroll"><table class="table compact sheet-table">
            <thead><tr><th class="sheet-name">Area</th><?php foreach ($divisions as $d): ?><th class="right th-sales"><?= e($d['name']) ?></th><?php endforeach; ?><th class="right">Area total</th></tr></thead>
            <tbody>
            <?php foreach ($areas as $a): ?>
                <tr><th class="sheet-name"><?= e($a['name']) ?></th>
                    <?php foreach ($divisions as $d): ?><?= $cell("a{$a['id']}d{$d['id']}", "area:{$d['id']}:{$a['id']}:0", "area{$a['id']}") ?><?php endforeach; ?>
                    <td class="right num"><b><output data-total-of="area<?= e($a['id']) ?>"></output></b></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="card table-card">
        <h2>3. Sales employee (annual ₹)</h2>
        <?php if (!$employees): ?><p class="empty">No sales employees in your scope.</p><?php else: ?>
        <div class="table-scroll"><table class="table compact sheet-table">
            <thead><tr><th class="sheet-name">Sales employee</th><th>Area</th><?php foreach ($divisions as $d): ?><th class="right th-sales"><?= e($d['name']) ?></th><?php endforeach; ?><th class="right">Total</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $em): ?>
                <tr><th class="sheet-name"><?= e($em['short_name'] ?: $em['name']) ?><div class="th-sub"><?= e($em['branch']) ?></div></th><td><?= e($em['area'] ?: '—') ?></td>
                    <?php foreach ($divisions as $d): ?><?= $cell("e{$em['id']}d{$d['id']}", "employee:{$d['id']}:0:{$em['id']}", "emp{$em['id']}") ?><?php endforeach; ?>
                    <td class="right num"><b><output data-total-of="emp<?= e($em['id']) ?>"></output></b></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save annual targets</button>
        <a class="btn" href="<?= e(url('targets') . '?fy=' . $fy['id']) ?>">Cancel</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
