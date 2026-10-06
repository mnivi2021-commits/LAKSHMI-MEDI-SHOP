<?php
/** @var string $activeTab users|roles */
use App\Core\Gate;
?>
<nav class="tabs" aria-label="Access sections">
    <?php if (Gate::allows('users.view')): ?>
        <a href="<?= e(url('access/users')) ?>" class="<?= $activeTab === 'users' ? 'active' : '' ?>">Users</a>
    <?php endif; ?>
    <?php if (Gate::allows('access.manage')): ?>
        <a href="<?= e(url('access/roles')) ?>" class="<?= $activeTab === 'roles' ? 'active' : '' ?>">Roles &amp; permissions</a>
    <?php endif; ?>
</nav>
