<?php
/** @var array<string, mixed> $report */
/** @var bool $limited */
$title = 'System Health';
$labels = ['ok' => 'Healthy', 'warn' => 'Needs attention', 'fail' => 'Failing'];
$status = $report['status'];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">System</p>
        <h1>Health check</h1>
    </div>
    <span class="badge badge-<?= e($status) ?> badge-lg"><?= e($labels[$status] ?? $status) ?></span>
</div>

<?php if ($limited): ?>
    <div class="card"><p class="muted">Detailed diagnostics are only shown on the server itself or when APP_ENV=local.</p></div>
<?php else: ?>
    <div class="grid-3">
        <div class="card kpi">
            <p class="kpi-label">Current financial year</p>
            <p class="kpi-value"><?= e($report['financial_year']['label']) ?></p>
            <p class="kpi-sub"><?= e($report['financial_year']['range']) ?></p>
        </div>
        <div class="card kpi">
            <p class="kpi-label">FY to previous day</p>
            <p class="kpi-value kpi-value-sm"><?= e($report['financial_year']['fy_to_previous_day']) ?></p>
            <p class="kpi-sub">Month to previous day: <?= e($report['financial_year']['month_to_previous_day']) ?></p>
        </div>
        <div class="card kpi">
            <p class="kpi-label">Today</p>
            <p class="kpi-value kpi-value-sm"><?= e($report['financial_year']['today']) ?></p>
            <p class="kpi-sub">Checked at <?= e($report['generated_at']) ?></p>
        </div>
    </div>

    <?php
    $groups = [];
    foreach ($report['checks'] as $check) {
        $groups[$check['group']][] = $check;
    }
    ?>
    <?php foreach ($groups as $group => $checks): ?>
        <section class="card table-card">
            <h2><?= e($group) ?></h2>
            <table class="table">
                <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($checks as $c): ?>
                    <tr>
                        <td><?= e($c['name']) ?></td>
                        <td><span class="badge badge-<?= e($c['status']) ?>"><?= e(strtoupper($c['status'])) ?></span></td>
                        <td class="muted"><?= e($c['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/minimal.php';
