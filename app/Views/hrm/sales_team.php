<?php
/** @var list<array<string, mixed>> $branches */
/** @var int|null $branch */
/** @var array<string, list<array<string, mixed>>> $groups */
/** @var array<int, list<array<string, mixed>>> $team */
/** @var int $unset */
/** @var bool $canEdit */
/** @var bool $canTarget */
/** @var bool $canDash */
use App\Modules\Hrm\SalesTeamController as T;

$d = static fn (?string $v): string => $v ? date('d-m-Y', strtotime($v)) : '—';
$age = static fn ($v): string => $v !== null ? (string) (int) $v : '—';
$name = static function (array $r) use ($canDash): string {
    $label = e($r['name']) . ($r['short_name'] ? ' <span class="muted small">' . e($r['short_name']) . '</span>' : '');
    return $canDash ? '<a href="' . e(url('/') . '?' . http_build_query(['branch' => $r['branch_id'], 'employee' => $r['id']])) . '" title="Open their dashboard">' . $label . '</a>' : $label;
};
$edit = static fn (array $r): string => $canEdit ? '<a class="btn btn-sm" href="' . e(url("hrm/{$r['id']}/edit")) . '">Edit</a>' : '';
$showBranch = $branch === null;
$hrmTab = 'team';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">HRM</p>
        <h1>Sales person details</h1>
        <p class="muted small">Choose a branch. Click a sales executive's name to open their dashboard.</p>
    </div>
    <?php if ($canTarget): ?>
        <a class="btn btn-primary btn-lg" href="<?= e(url('entry') . '?' . http_build_query(array_filter(['type' => 'month', 'branch' => $branch]))) ?>">+ ADD target</a>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<nav class="tabs" aria-label="HRM">
    <a href="<?= e(url('hrm')) ?>">Employees</a>
    <a href="<?= e(url('hrm/sales-team')) ?>" class="active">Sales person details</a>
    <a href="<?= e(url('hrm/lists/departments')) ?>">Departments</a>
    <a href="<?= e(url('hrm/lists/designations')) ?>">Designations</a>
</nav>

<form method="get" action="<?= e(url('hrm/sales-team')) ?>" class="card fu-filters" aria-label="Branch">
    <label class="field">
        <span>Branch</span>
        <select name="branch" data-autosubmit>
            <?php if (count($branches) !== 1): ?><option value="">All branches</option><?php endif; ?>
            <?php foreach ($branches as $b): ?>
                <option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === $branch ? ' selected' : '' ?>><?= e($b['name'] . ' (' . $b['branch_code'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="form-actions"><button type="submit" class="btn">Show</button></div>
</form>

<?php if ($unset > 0): ?>
    <div class="alert alert-info"><?= e($unset) ?> sales representative(s) have no sales role yet. Set it on the employee's Edit page (HRM → Employees).</div>
<?php endif; ?>

<?php foreach (['manager' => 'Manager', 'sales_executive' => 'Sales Executives', 'sales_support' => 'Sales Support Admin', 'sales_coordinator' => 'Sales Coordinator'] as $role => $heading): $rows = $groups[$role]; ?>
<section class="card team-section">
    <h2 class="card-title"><?= e($heading) ?> <span class="muted small">(<?= count($rows) ?>)</span></h2>
    <?php if (!$rows): ?>
        <p class="empty">None<?= $branch !== null ? ' in this branch' : '' ?>.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr>
            <th>S.No</th><th>Name</th><?php if ($showBranch): ?><th>Branch</th><?php endif; ?>
            <th class="right">Age</th><th>Date of birth</th>
            <?php if ($role === 'sales_executive'): ?><th>Area</th><th>Coordinator</th><?php endif; ?>
            <?php if ($role === 'manager'): ?><th>Designation</th><?php endif; ?>
            <?php if ($role === 'sales_coordinator'): ?><th>Area sales persons</th><?php endif; ?>
            <th>Mobile</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $role === 'sales_executive' ? $name($r) : e($r['name']) ?></td>
                <?php if ($showBranch): ?><td><?= e($r['branch_code']) ?></td><?php endif; ?>
                <td class="right num"><?= e($age($r['age'])) ?></td>
                <td><?= e($d($r['date_of_birth'])) ?></td>
                <?php if ($role === 'sales_executive'): ?><td><?= e($r['area'] ?? '—') ?></td><td><?= e($r['coordinator'] ?? '—') ?></td><?php endif; ?>
                <?php if ($role === 'manager'): ?><td><?= e($r['designation'] ?? '—') ?></td><?php endif; ?>
                <?php if ($role === 'sales_coordinator'): ?>
                    <td><?php $mine = $team[(int) $r['id']] ?? []; ?>
                        <?= $mine ? implode(', ', array_map(static fn ($m) => e(($m['short_name'] ?: $m['name']) . ($m['area'] ? ' (' . $m['area'] . ')' : '')), $mine)) : '<span class="muted">—</span>' ?>
                        <?= $r['area'] ? '<div class="muted small">Area: ' . e($r['area']) . '</div>' : '' ?></td>
                <?php endif; ?>
                <td><?= e($r['mobile'] ?? '—') ?></td>
                <td class="right nowrap">
                    <?php if ($role === 'sales_executive' && $canTarget): ?>
                        <a class="btn btn-sm" href="<?= e(url('entry') . '?' . http_build_query(['type' => 'month', 'branch' => $r['branch_id']])) ?>">+ ADD target</a>
                    <?php endif; ?>
                    <?= $edit($r) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
