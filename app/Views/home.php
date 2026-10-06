<?php
ob_start();
?>
<section class="hero card">
    <p class="eyebrow">Phase 1 · Foundation</p>
    <h1>Marketing CRM</h1>
    <p class="muted">Project architecture, database and configuration are installed. Sign-in arrives in Phase 3 and the management dashboard in Phase 5.</p>
    <div class="actions">
        <a class="btn btn-primary" href="<?= e(url('health')) ?>">Open system health check</a>
        <a class="btn" href="<?= e(url('api/health')) ?>">API health (JSON)</a>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/minimal.php';
