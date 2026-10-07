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
/** @var list<array<string, mixed>> $branches */
/** @var int $branch */
/** @var bool $canEntry */
$branches ??= [];
$branch ??= 0;
$canEntry ??= false;
use App\Modules\Dashboard\TargetController;

$months = TargetController::months($fy);
$val = static fn (string $key): int => $map[$key] ?? 0;
$money = static fn (int $p): string => $p > 0 ? rupees($p) : '—';
$month = static fn (int $p): string => $p > 0 ? rupees((int) round($p / 12)) : '—';
ob_start();
?>
<div class="page-head dash-head">
    <h1 class="company-title"><?= e(\App\Modules\Sms\SmsService::company()) ?></h1>
    <div class="form-actions">
        <?php if ($canEntry): ?><a class="btn btn-lg" href="<?= e(url('entry')) ?>">+ ADD</a><?php endif; ?>
        <?php if ($canEdit): ?><a class="btn btn-primary btn-lg" href="<?= e(url('targets/edit') . '?fy=' . $fy['id']) ?>">+ Set annual targets</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<!-- Branch annual target (choose a branch) -->
<?php
$chosen = null;
foreach ($branches as $br) { if ((int) $br['id'] === $branch) { $chosen = $br; } }
$shown = $chosen ? [$chosen] : $branches;
// The chosen branch also narrows the areas, sales employees and coordinators below
if ($chosen) {
    $areas = array_values(array_filter($areas, static fn ($a) => (int) ($a['branch_id'] ?? 0) === (int) $chosen['id']));
    $employees = array_values(array_filter($employees, static fn ($m) => (int) $m['branch_id'] === (int) $chosen['id']));
    $coordinators = array_values(array_filter($coordinators, static fn ($c) => (int) $c['branch_id'] === (int) $chosen['id']));
}
?>
<section class="card tg-box">
    <div class="tg-branch-head">
        <h2 class="card-title"><?= e(strtoupper($chosen ? $chosen['name'] : 'All branches')) ?> ANNUAL TARGET <?= e(str_replace('FY ', '', $fy['label'])) ?></h2>
        <form method="get" action="<?= e(url('/')) ?>" class="tg-branch-pick">
            <input type="hidden" name="fy" value="<?= e($fy['id']) ?>">
            <label class="field"><span>Branch</span>
                <select name="branch" data-autosubmit>
                    <option value="">All branches</option>
                    <?php foreach ($branches as $br): ?><option value="<?= e($br['id']) ?>"<?= (int) $br['id'] === $branch ? ' selected' : '' ?>><?= e($br['name']) ?></option><?php endforeach; ?>
                </select></label>
        </form>
    </div>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Branch</th><th class="right">Annual target</th><th>Month</th><th class="right">This month</th></tr></thead>
        <tbody>
        <?php $bsum = 0; foreach ($shown as $br): $a = $val("branch:0:0:0:{$br['id']}"); $bsum += $a; ?>
            <tr><td><b><?= e($br['name']) ?></b></td><td class="right num"><?= e($money($a)) ?></td><td><?= e($months) ?></td><td class="right num"><?= e($month($a)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (count($shown) > 1): ?>
        <tfoot><tr class="total-row"><th>All branches</th><th class="right num"><?= e($money($bsum)) ?></th><th><?= e($months) ?></th><th class="right num"><?= e($month($bsum)) ?></th></tr></tfoot>
        <?php endif; ?>
    </table></div>
</section>

<!-- 2. Area-wise: one separate box per area -->
<h2 class="section-title">Area-wise target<?= $chosen ? ' · ' . e($chosen['name']) : '' ?></h2>
<?php if (!$areas): ?><div class="card"><p class="empty">No sales areas for this branch yet. Add them under HRM → Sales areas and choose the branch.</p></div><?php endif; ?>
<div class="tg-areas">
    <?php foreach ($areas as $ar): $at = 0; ?>
    <section class="card tg-area">
        <header class="tg-area-head"><h3><?= e($ar['name']) ?></h3></header>
        <table class="table compact">
            <thead><tr><th>Division</th><th class="right">Annual</th><th class="right">Month</th></tr></thead>
            <tbody>
            <?php foreach ($divisions as $d): $a = $val("area:{$d['id']}:{$ar['id']}:0:0"); $at += $a; ?>
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
    <h2 class="card-title">Sales employee target</h2>
    <?php if (!$employees): ?><p class="empty">No sales employees.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>Sales employee</th><th>Area</th><th>Division</th><th class="right">Annual target</th><th class="right">Current month target</th></tr></thead>
        <tbody>
        <?php $tot = 0; foreach ($employees as $em):
            $rows = [];
            foreach ($divisions as $d) {
                $a = $val("employee:{$d['id']}:0:{$em['id']}:0");
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
        <h2 class="card-title">Sales coordinators (support team)</h2>
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
        <h2 class="card-title">Admin Head · Sales Manager</h2>
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
