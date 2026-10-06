<?php
/** @var string $title */
/** @var string $content */
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Request;

$user = Auth::user();
$path = Request::path();
$menu = array_filter(Config::get('menu', []), static fn (array $m): bool => Gate::allowsAny($m['any'], $user));
// Active when the current path is in the item's first URL segment ("/access/roles/3" -> Access).
$isActive = static function (array $m) use ($path): bool {
    if ($m['path'] === '/') {
        return $path === '/';
    }
    $section = '/' . explode('/', trim($m['path'], '/'))[0];
    return str_starts_with($path . '/', $section . '/');
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Marketing CRM') ?></title>
    <meta name="app-base" content="<?= e(url('')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="has-sidebar">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="btn btn-sm nav-toggle" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">Menu</button>
            <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark">M</span> Marketing CRM</a>
        </div>
        <div class="topbar-right">
            <div class="user-chip">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                <span class="user-meta">
                    <span class="user-name"><?= e($user['name']) ?></span>
                    <span class="user-role"><?= e($user['role_name']) ?></span>
                </span>
            </div>
            <a class="btn btn-sm topbar-password" href="<?= e(url('password/change')) ?>">Change password</a>
            <form method="post" action="<?= e(url('logout')) ?>" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-sm">Sign out</button>
            </form>
        </div>
    </header>

    <div class="shell">
        <nav id="sidebar" class="sidebar" aria-label="Main">
            <?php if (!$user['must_change_password']): ?>
                <ul>
                    <?php foreach ($menu as $m): ?>
                        <li>
                            <?php if ($m['ready']): ?>
                                <a href="<?= e(url($m['path'])) ?>" class="<?= $isActive($m) ? 'active' : '' ?>"<?= $isActive($m) ? ' aria-current="page"' : '' ?>><?= e($m['label']) ?></a>
                            <?php else: ?>
                                <span class="soon" title="Coming in a later phase"><?= e($m['label']) ?> <small>Soon</small></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <ul class="sidebar-account">
                <li><a href="<?= e(url('password/change')) ?>">Change password</a></li>
            </ul>
        </nav>
        <main class="main">
            <?= $content ?>
        </main>
    </div>
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?= e(url('assets/js/' . $script)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
