<?php
/** @var string $title */
/** @var string $content */
/** @var array<string, mixed> $user */
use App\Core\Csrf;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Marketing CRM') ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark">M</span> Marketing CRM</a>
        <div class="topbar-right">
            <div class="user-chip">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                <span class="user-meta">
                    <span class="user-name"><?= e($user['name']) ?></span>
                    <span class="user-role"><?= e($user['role_name']) ?></span>
                </span>
            </div>
            <a class="btn btn-sm" href="<?= e(url('password/change')) ?>">Change password</a>
            <form method="post" action="<?= e(url('logout')) ?>" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-sm">Sign out</button>
            </form>
        </div>
    </header>
    <main class="container">
        <?= $content ?>
    </main>
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
