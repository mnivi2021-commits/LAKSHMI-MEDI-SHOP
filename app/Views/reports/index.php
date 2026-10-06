<?php
/** @var array<string, list<\App\Modules\Reports\Report>> $groups */
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Reports</p>
        <h1>Reports</h1>
        <p class="muted small">Every report reads the same data as the dashboard, so its totals agree with the dashboard figures for the same period and filters.</p>
    </div>
</div>

<?php if ($groups === []): ?>
    <p class="empty card">You do not have access to any report yet. Ask the Admin Head.</p>
<?php endif; ?>
<?php foreach ($groups as $group => $reports): ?>
    <h2 class="section-title"><?= e($group) ?></h2>
    <section class="import-types">
        <?php foreach ($reports as $r): ?>
            <a class="card import-type report-card" href="<?= e(url('reports/' . $r->key())) ?>">
                <h2><?= e($r->title()) ?></h2>
                <p class="muted small"><?= e($r->description()) ?></p>
            </a>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
