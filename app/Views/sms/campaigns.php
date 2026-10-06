<?php
/** @var list<array<string, mixed>> $campaigns */
/** @var bool $testMode */
use App\Modules\Sms\SmsService;

$badge = ['completed' => 'badge-ok', 'processing' => 'badge-warn', 'scheduled' => 'badge-info', 'draft' => 'badge-muted', 'cancelled' => 'badge-muted', 'failed' => 'badge-fail'];
$smsTab = 'campaigns';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SMS</p>
        <h1>Campaigns</h1>
    </div>
    <div class="form-actions"><a class="btn btn-primary" href="<?= e(url('sms/campaigns/new')) ?>">New campaign</a></div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="card table-card">
    <?php if ($campaigns === []): ?>
        <p class="empty">No campaigns yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table compact">
        <thead><tr><th>Campaign</th><th>Sent to</th><th>When</th><th class="right">Recipients</th><th class="right">Sent</th><th class="right">Failed</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><a href="<?= e(url("sms/campaigns/{$c['id']}")) ?>"><strong><?= e($c['name']) ?></strong></a><div class="muted small"><?= e($c['template_name'] ?? 'Own text') ?> · <?= e($c['user_name'] ?? '') ?></div></td>
                <td class="small"><?= e(SmsService::TARGETS[$c['target_type']] ?? $c['target_type']) ?></td>
                <td class="small nowrap"><?= $c['scheduled_at'] ? 'Scheduled ' . e(date('d-m-Y H:i', strtotime($c['scheduled_at']))) : ($c['started_at'] ? e(date('d-m-Y H:i', strtotime($c['started_at']))) : '—') ?></td>
                <td class="right num"><?= e($c['total_recipients']) ?></td>
                <td class="right num"><?= e($c['sent_count']) ?></td>
                <td class="right num<?= $c['failed_count'] > 0 ? ' neg' : '' ?>"><?= e($c['failed_count']) ?></td>
                <td><span class="badge <?= e($badge[$c['status']] ?? 'badge-muted') ?>"><?= e(ucfirst($c['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
