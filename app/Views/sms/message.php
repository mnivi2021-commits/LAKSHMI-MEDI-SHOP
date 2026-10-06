<?php
/** @var array<string, mixed> $m */
/** @var list<array<string, mixed>> $logs */
use App\Modules\Sms\SmsController;

ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('sms')) ?>">SMS</a></p>
        <h1>SMS to <?= e($m['recipient_mobile']) ?></h1>
        <p class="muted small"><?= e($m['recipient_name'] ?? '') ?> · created <?= e(date('d-m-Y H:i', strtotime($m['created_at']))) ?>
            <?= $m['campaign_id'] ? ' · campaign <a href="' . e(url("sms/campaigns/{$m['campaign_id']}")) . '">' . e($m['campaign_name']) . '</a>' : '' ?>
            <?= $m['template_name'] ? ' · template ' . e($m['template_name']) : '' ?></p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php if ($m['is_test']): ?><div class="alert alert-warning">Test message: recorded by the test gateway, <strong>not sent</strong> to the phone.</div><?php endif; ?>

<section class="card padded-card">
    <p class="sms-bubble"><?= nl2br(e($m['message'])) ?></p>
    <dl class="kv">
        <dt>Status</dt><dd><?= e(SmsController::STATUSES[$m['status']]) ?><?= $m['error_message'] ? ' – <span class="neg">' . e($m['error_message']) . '</span>' : '' ?></dd>
        <dt>Length</dt><dd><?= e($m['segments']) ?> SMS part(s), <?= $m['encoding'] === 'unicode' ? 'Unicode' : 'GSM' ?></dd>
        <dt>Gateway</dt><dd><?= e($m['gateway']) ?><?= $m['provider_message_id'] ? ' · ' . e($m['provider_message_id']) : '' ?></dd>
        <dt>Sent / delivered</dt><dd><?= $m['sent_at'] ? e(date('d-m-Y H:i:s', strtotime($m['sent_at']))) : '—' ?> / <?= $m['delivered_at'] ? e(date('d-m-Y H:i:s', strtotime($m['delivered_at']))) : '—' ?></dd>
        <?php if ($m['scheduled_at']): ?><dt>Scheduled</dt><dd><?= e(date('d-m-Y H:i', strtotime($m['scheduled_at']))) ?></dd><?php endif; ?>
        <dt>Attempts</dt><dd><?= e($m['attempts']) ?></dd>
    </dl>
</section>

<section class="card table-card">
    <h2>Gateway log</h2>
    <?php if ($logs === []): ?><p class="empty">Not sent yet.</p><?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Time</th><th>Event</th><th>HTTP</th><th>Details (numbers masked, no keys)</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr><td class="small nowrap"><?= e(date('d-m-Y H:i:s', strtotime($l['created_at']))) ?></td><td><?= e($l['event']) ?></td><td><?= e($l['http_status'] ?? '—') ?></td>
                <td class="small"><code><?= e($l['payload']) ?></code></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
