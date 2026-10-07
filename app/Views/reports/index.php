<?php
/** @var array<string, list<\App\Modules\Reports\Report>> $groups */
/** @var list<array<string, mixed>> $branches */
/** @var list<array<string, mixed>> $employees */
/** @var int|null $branch */
/** @var int|null $employee */
$pick = http_build_query(array_filter(['branch' => $branch, 'employee' => $employee]));
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Reports</p>
        <h1>Reports</h1>
        <p class="muted small">Every report reads the same data as the dashboard, so its totals agree with the dashboard figures for the same period and filters.</p>
    </div>
</div>

<form method="get" action="<?= e(url('reports')) ?>" class="card fu-filters" aria-label="Report selection">
    <label class="field">
        <span>Branch</span>
        <select name="branch" data-autosubmit>
            <?php if (count($branches) !== 1): ?><option value="">All branches</option><?php endif; ?>
            <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === $branch ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Sales executive</span>
        <select name="employee" data-autosubmit>
            <?php if (count($employees) !== 1): ?><option value="">All sales executives</option><?php endif; ?>
            <?php foreach ($employees as $em): ?><option value="<?= e($em['id']) ?>"<?= (int) $em['id'] === $employee ? ' selected' : '' ?>><?= e(($em['short_name'] ?: $em['name']) . ' - ' . $em['name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <div class="form-actions"><button type="submit" class="btn">Apply</button></div>
    <p class="muted small field-wide">The branch and sales executive chosen here are applied when you open a report.</p>
</form>

<?php if ($groups === []): ?>
    <p class="empty card">You do not have access to any report yet. Ask the Admin Head.</p>
<?php endif; ?>
<?php foreach ($groups as $group => $reports): ?>
    <h2 class="section-title"><?= e($group) ?></h2>
    <section class="import-types">
        <?php foreach ($reports as $r): ?>
            <a class="card import-type report-card" href="<?= e(url('reports/' . $r->key()) . ($pick !== '' ? '?' . $pick : '')) ?>">
                <h2><?= e($r->title()) ?></h2>
                <p class="muted small"><?= e($r->description()) ?></p>
            </a>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
