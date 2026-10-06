<?php
/** @var array<string, mixed> $user */
ob_start();
?>
<?php require __DIR__ . '/partials/flash.php'; ?>

<section class="card hero">
    <p class="eyebrow">Signed in</p>
    <h1>Welcome, <?= e($user['name']) ?></h1>
    <p class="muted">
        Role: <strong><?= e($user['role_name']) ?></strong>
        <?php if (!empty($user['last_login_at'])): ?>
            · Signed in at <?= e(date('d-m-Y H:i', strtotime((string) $user['last_login_at']))) ?>
        <?php endif; ?>
    </p>
    <p class="muted">The management dashboard (Sales Performance, Payment Collection, Branch Pending Order) arrives in Phase 5-8.</p>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/app.php';
