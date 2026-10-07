<?php
/** @var list<array<string, mixed>> $employees */
/** @var array<string, mixed> $filters */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $departments */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\Csrf;
use App\Modules\Hrm\EmployeeController;

$query = static fn (array $extra): string => http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== '' && $v !== null));
$statusBadge = ['active' => 'badge-ok', 'inactive' => 'badge-muted', 'resigned' => 'badge-fail'];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">HRM</p>
        <h1>Employees</h1>
    </div>
    <div class="form-actions">
        <?php if ($canEdit): ?>
            <a class="btn" href="<?= e(url('hrm/sales-team')) ?>">Sales person details</a>
            <a class="btn" href="<?= e(url('hrm/lists/departments')) ?>">Departments</a>
            <a class="btn" href="<?= e(url('hrm/lists/designations')) ?>">Designations</a>
        <?php endif; ?>
        <?php if ($canExport): ?><a class="btn" href="<?= e(url('hrm/export')) ?>">Export CSV</a><?php endif; ?>
        <?php if ($canAdd): ?><a class="btn btn-primary" href="<?= e(url('hrm/new')) ?>">Add employee</a><?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<form method="get" action="<?= e(url('hrm')) ?>" class="filters card">
    <label class="field">
        <span>Search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Code, name, short name, mobile, email">
    </label>
    <label class="field">
        <span>Branch</span>
        <select name="branch">
            <option value="">All</option>
            <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === $filters['branch'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Department</span>
        <select name="department">
            <option value="">All</option>
            <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= (int) $d['id'] === $filters['department'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Status</span>
        <select name="status">
            <option value="">Any</option>
            <?php foreach (EmployeeController::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </label>
    <div class="filter-actions">
        <label class="check"><input type="checkbox" name="reps" value="1"<?= $filters['reps'] === '1' ? ' checked' : '' ?>> Sales reps only</label>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="<?= e(url('hrm')) ?>">Clear</a>
    </div>
</form>

<section class="card table-card">
    <h2><?= e($total) ?> employee<?= $total === 1 ? '' : 's' ?></h2>
    <?php if ($employees === []): ?>
        <p class="empty">No employees match these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Employee</th><th>Contact</th><th>Branch</th><th>Department / designation</th><th>Reports to</th><th>Login</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $m): ?>
            <tr>
                <td><strong><?= e($m['name']) ?></strong>
                    <div class="muted small"><?= e($m['employee_code']) ?><?= $m['short_name'] ? ' · ' . e($m['short_name']) : '' ?><?= $m['is_sales_rep'] ? ' · <span class="badge badge-info">Sales rep</span>' : '' ?></div></td>
                <td class="small"><?= e($m['mobile'] ?? '—') ?><?= $m['email'] ? '<div class="muted">' . e($m['email']) . '</div>' : '' ?></td>
                <td class="small"><?= e($m['branch_code']) ?></td>
                <td class="small"><?= e($m['department'] ?? '—') ?><?= $m['designation'] ? '<div class="muted">' . e($m['designation']) . '</div>' : '' ?></td>
                <td class="small"><?= e($m['manager'] ?? '—') ?></td>
                <td class="small"><?= $m['username'] ? e($m['username']) . ($m['user_status'] !== 'active' ? ' <span class="muted">(' . e($m['user_status']) . ')</span>' : '') : '<span class="muted">—</span>' ?></td>
                <td><span class="badge <?= e($statusBadge[$m['status']] ?? 'badge-muted') ?>"><?= e(EmployeeController::STATUSES[$m['status']]) ?></span>
                    <?php if ($m['status'] === 'resigned' && $m['relieving_date']): ?><div class="muted small">from <?= e(date('d-m-Y', strtotime($m['relieving_date']))) ?></div><?php endif; ?></td>
                <td class="right">
                    <?php if ($canEdit || $canDelete): ?>
                    <details class="row-menu">
                        <summary class="btn btn-sm">Actions</summary>
                        <div class="row-menu-panel">
                            <?php if ($canEdit): ?><a href="<?= e(url("hrm/{$m['id']}/edit")) ?>">Edit</a><?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url("hrm/{$m['id']}/delete")) ?>" data-confirm="Delete <?= e($m['name']) ?>?">
                                    <?= Csrf::field() ?><button type="submit" class="danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
