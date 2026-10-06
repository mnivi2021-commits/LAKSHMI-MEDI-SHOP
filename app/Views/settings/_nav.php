<?php
/** @var string $settingsTab */
use App\Core\Gate;
?>
<nav class="tabs" aria-label="Settings">
    <?php if (Gate::allows('settings.manage')): ?><a href="<?= e(url('settings')) ?>" class="<?= $settingsTab === 'settings' ? 'active' : '' ?>">Settings</a><?php endif; ?>
    <?php if (Gate::allows('audit.view')): ?><a href="<?= e(url('settings/audit')) ?>" class="<?= $settingsTab === 'audit' ? 'active' : '' ?>">Audit log</a><?php endif; ?>
    <a href="<?= e(url('health')) ?>">System health</a>
</nav>
