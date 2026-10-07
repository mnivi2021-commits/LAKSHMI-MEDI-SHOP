<?php
/** @var array<string, mixed> $fy */
/** @var list<array<string, mixed>> $fyList */
/** @var list<array<string, mixed>> $divisions */
/** @var list<array<string, mixed>> $areas */
/** @var list<array<string, mixed>> $employees */
/** @var array<string, int> $map */
/** @var list<array<string, mixed>> $coordinators */
/** @var list<array<string, mixed>> $leaders */
/** @var bool $canEdit */
/** @var list<array<string, mixed>> $branchTotals */
/** @var bool $canEntry */
$branchTotals ??= [];
$canEntry ??= false;
use App\Modules\Dashboard\TargetController;

$months = TargetController::months($fy);
$val = static fn (string $key): int => $map[$key] ?? 0;
$money = static fn (int $p): string => $p > 0 ? rupees($p) : '—';
$month = static fn (int $p): string => $p > 0 ? rupees((int) round($p / 12)) : '—';
ob_start();
?>
<div class="page-head dash-head">
    <div>
        <p class="eyebrow">Management dashboard</p>
        <h1>Targets</h1>
        <p class="asof">Year <strong><?= e($fy['label']) ?></strong> · <?= e($months) ?> · current month target = annual ÷ 12</p>
    </div>
    <div class="form-actions">
        <?php if ($canEntry): ?><a class="btn btn-lg" href="<?= e(url('entry')) ?>">+ ADD</a><?php endif; ?>
        <?php if ($canEdit): ?><a class="btn btn-primary btn-lg" href="<?= e(url('targets/edit') . '?fy=' . $fy['id']) ?>">+ Set annual targets</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('/')) ?>" class="card fu-filters" aria-label="Year">
    <label class="field"><span>Year</span>
        <select name="fy" data-autosubmit>
            <?php foreach ($fyList as $f): ?><option value="<?= e($f['id']) ?>"<?= (int) $f['id'] === (int) $fy['id'] ? ' selected' : '' ?>><?= e($f['label']) ?></option><?php endforeach; ?>
        </select></label>
    <div class="form-actions"><button type="submit" class="btn">Show</button></div>
</form>

<!-- 1. Division -->
<section class="card tg-box">
    <h2 class="card-title">1. Division target</h2>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Year</th><th>Division</th><th class="right">Annual target</th><th>Month</th><th class="right">Current month target</th></tr></thead>
        <tbody>
        <?php $tot = 0; foreach ($divisions as $d): $a = $val("division:{$d['id']}:0:0"); $tot += $a; ?>
            <tr><td><?= e($fy['label']) ?></td><td><b><?= e($d['name']) ?></b></td><td class="right num"><?= e($money($a)) ?></td><td><?= e($months) ?></td><td class="right num"><?= e($month($a)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><th><?= e($fy['label']) ?></th><th>All divisions</th><th class="right num"><?= e($money($tot)) ?></th><th><?= e($months) ?></th><th class="right num"><?= e($month($tot)) ?></th></tr></tfoot>
    </table></div>
</section>

<!-- Branch total: sum of the sales employees' annual targets in each branch -->
<section class="card tg-box">
    <h2 class="card-title">Branch total target</h2>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Year</th><th>Branch</th><?php foreach ($divisions as $d): ?><th class="right"><?= e($d['name']) ?></th><?php endforeach; ?><th class="right">Annual target</th><th>Month</th><th class="right">Current month target</th></tr></thead>
        <tbody>
        <?php $grand = 0; $gd = []; foreach ($branchTotals as $b): $bt = 0; ?>
            <tr><td><?= e($fy['label']) ?></td><td><b><?= e($b['name']) ?></b></td>
                <?php foreach ($divisions as $d): $a = $b['div'][(int) $d['id']] ?? 0; $bt += $a; $gd[(int) $d['id']] = ($gd[(int) $d['id']] ?? 0) + $a; ?><td class="right num"><?= e($money($a)) ?></td><?php endforeach; ?>
                <td class="right num"><b><?= e($money($bt)) ?></b></td><td><?= e($months) ?></td><td class="right num"><?= e($month($bt)) ?></td></tr>
        <?php $grand += $bt; endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><th><?= e($fy['label']) ?></th><th>All branches</th>
            <?php foreach ($divisions as $d): ?><th class="right num"><?= e($money($gd[(int) $d['id']] ?? 0)) ?></th><?php endforeach; ?>
            <th class="right num"><?= e($money($grand)) ?></th><th><?= e($months) ?></th><th class="right num"><?= e($month($grand)) ?></th></tr></tfoot>
    </table></div>
    <p class="muted small padded">Branch figures add up the annual targets of the sales people in each branch (3. Sales employee target).</p>
</section>

<!-- 2. Area-wise: one separate box per area -->
<h2 class="section-title">2. Area-wise target</h2>
<div class="tg-areas">
    <?php foreach ($areas as $ar): $at = 0; ?>
    <section class="card tg-area">
        <header class="tg-area-head"><h3><?= e($ar['name']) ?></h3><span class="muted small"><?= e($fy['label']) ?> · <?= e($months) ?></span></header>
        <table class="table compact">
            <thead><tr><th>Division</th><th class="right">Annual</th><th class="right">Month</th></tr></thead>
            <tbody>
            <?php foreach ($divisions as $d): $a = $val("area:{$d['id']}:{$ar['id']}:0"); $at += $a; ?>
                <tr><td><?= e($d['name']) ?></td><td class="right num"><?= e($money($a)) ?></td><td class="right num"><?= e($month($a)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><th>Total</th><th class="right num"><?= e($money($at)) ?></th><th class="right num"><?= e($month($at)) ?></th></tr></tfoot>
        </table>
    </section>
    <?php endforeach; ?>
</div>

<!-- 3. Sales employees -->
<section class="card tg-box">
    <h2 class="card-title">3. Sales employee target</h2>
    <?php if (!$employees): ?><p class="empty">No sales employees.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Sales employee</th><th>Area</th><th>Division</th><th class="right">Annual target</th><th class="right">Current month target</th></tr></thead>
        <tbody>
        <?php $tot = 0; foreach ($employees as $em):
            $rows = [];
            foreach ($divisions as $d) {
                $a = $val("employee:{$d['id']}:0:{$em['id']}");
                if ($a > 0) { $rows[] = [$d['name'], $a]; $tot += $a; }
            }
            if (!$rows) { $rows[] = ['—', 0]; }
            foreach ($rows as $i => [$dn, $a]): ?>
            <tr>
                <td><?= $i === 0 ? '<b>' . e($em['short_name'] ?: $em['name']) . '</b> <span class="muted small">' . e($em['name']) . '</span>' : '' ?></td>
                <td><?= $i === 0 ? e($em['area'] ?: '—') : '' ?></td>
                <td><?= e($dn) ?></td>
                <td class="right num"><?= e($money($a)) ?></td>
                <td class="right num"><?= e($month($a)) ?></td>
            </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><th colspan="3">Total</th><th class="right num"><?= e($money($tot)) ?></th><th class="right num"><?= e($month($tot)) ?></th></tr></tfoot>
    </table></div>
    <?php endif; ?>
</section>

<div class="tg-two">
    <!-- 4. Sales coordinators -->
    <section class="card tg-box">
        <h2 class="card-title">4. Sales coordinators (support team)</h2>
        <?php if (!$coordinators): ?><p class="empty">No sales coordinators. Set Sales role = Sales Coordinator in HRM.</p><?php else: ?>
        <table class="table compact">
            <thead><tr><th>Sales coordinator</th><th>Area</th><th>Sales reps</th><th>Division</th></tr></thead>
            <tbody>
            <?php foreach ($coordinators as $c): ?>
                <tr><td><b><?= e($c['name']) ?></b><div class="muted small"><?= e($c['branch']) ?></div></td><td><?= e($c['area'] ?: '—') ?></td><td><?= e($c['reps'] ?: '—') ?></td><td><?= e($c['divisions'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- 5. Admin Head / Sales Manager -->
    <section class="card tg-box">
        <h2 class="card-title">5. Admin Head · Sales Manager</h2>
        <?php if (!$leaders): ?><p class="empty">No users with these roles yet.</p><?php else: ?>
        <table class="table compact">
            <thead><tr><th>Name</th><th>Role</th><th>Branch</th></tr></thead>
            <tbody>
            <?php foreach ($leaders as $l): ?><tr><td><b><?= e($l['name']) ?></b></td><td><?= e($l['role']) ?></td><td><?= e($l['branch'] ?? 'All branches') ?></td></tr><?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
