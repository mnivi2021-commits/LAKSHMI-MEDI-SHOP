<?php
/** @var string $title */
/** @var string $content */
/** @var bool|null $hideSignIn */
// Session-only check (no DB call): this layout also renders error pages when the DB is down.
$signedIn = isset($_SESSION['auth']);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <link rel="icon" href="<?= e(url('assets/images/logo.svg')) ?>" type="image/svg+xml">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Marketing CRM') ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="minimal">
    <header class="topbar">
        <?php $company = (static function (): string { try { return (string) (\App\Core\Settings::get('company', 'name', null) ?: 'Marketing CRM'); } catch (\Throwable) { return 'Marketing CRM'; } })(); ?><a class="brand" href="<?= e(url('/')) ?>"><img class="brand-logo" src="<?= e(url('assets/images/logo.svg')) ?>" alt="" width="30" height="30"> <?= e($company) ?></a>
        <div class="topbar-right">
            <?php if ($signedIn): ?>
                <a class="btn btn-sm" href="<?= e(url('/')) ?>">Open CRM</a>
            <?php elseif (empty($hideSignIn)): ?>
                <a class="btn btn-sm btn-primary" href="<?= e(url('login')) ?>">Sign in</a>
            <?php endif; ?>
        </div>
    </header>
    <main class="container">
        <?= $content ?>
    </main>
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
