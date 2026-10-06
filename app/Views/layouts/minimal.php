<?php
/** @var string $title */
/** @var string $content */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Marketing CRM') ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="minimal">
    <header class="topbar">
        <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark">M</span> Marketing CRM</a>
    </header>
    <main class="container">
        <?= $content ?>
    </main>
</body>
</html>
