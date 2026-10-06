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
    <p class="muted">Your role does not include the management dashboard. Use the menu to open the modules you have access to,
        or ask the Admin Head if you need more.</p>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/app.php';
