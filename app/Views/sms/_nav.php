<?php
/** @var string $smsTab */
/** @var bool $testMode */
use App\Core\Gate;

$tabs = ['history' => ['sms', 'History', 'sms.view'], 'send' => ['sms/send', 'Send SMS', 'sms.send'],
         'campaigns' => ['sms/campaigns', 'Campaigns', 'sms.bulk'], 'templates' => ['sms/templates', 'Templates', 'sms.manage']];
?>
<?php if ($testMode): ?>
    <div class="alert alert-warning" role="status"><strong>TEST MODE</strong> – the SMS gateway is the built-in test gateway. Messages are recorded but <strong>no real SMS is sent</strong>. Numbers ending in 0000 simulate a failure.</div>
<?php endif; ?>
<nav class="tabs" aria-label="SMS">
    <?php foreach ($tabs as $key => [$path, $label, $perm]): if (!Gate::allows($perm)) { continue; } ?>
        <a href="<?= e(url($path)) ?>" class="<?= $smsTab === $key ? 'active' : '' ?>"<?= $smsTab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
