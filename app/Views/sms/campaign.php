<?php
/** @var array<string, mixed> $c */
/** @var array{ready: list<array<string, mixed>>, excluded: array<string, int>, segments: int}|null $preview */
/** @var array<string, int> $byStatus */
/** @var bool $testMode */
/** @var string $category */
use App\Core\Csrf;
use App\Modules\Sms\SmsController;
use App\Modules\Sms\SmsService;

$filter = json_decode((string) $c['target_filter'], true) ?: [];
$exLabels = ['opted_out' => 'opted out of SMS', 'no_mobile' => 'no valid mobile', 'duplicate' => 'same mobile twice', 'missing_value' => 'a placeholder has no value'];
$smsTab = 'campaigns';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('sms/campaigns')) ?>">SMS campaigns</a></p>
        <h1><?= e($c['name']) ?></h1>
        <p class="muted small"><?= e(SmsService::TARGETS[$c['target_type']] ?? '') ?><?= !empty($filter['overdue_only']) ? ' with overdue bills' : '' ?>
            · <?= e($c['template_name'] ?? 'own text') ?> (<?= e($category) ?>) · status <strong><?= e(ucfirst($c['status'])) ?></strong>
            <?= $c['scheduled_at'] ? ' · scheduled ' . e(date('d-m-Y H:i', strtotime($c['scheduled_at']))) : '' ?></p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="card padded-card">
    <h2>Message</h2>
    <p class="sms-bubble"><?= nl2br(e($c['message_body'])) ?></p>
</section>

<?php if ($preview !== null): ?>
    <section class="card import-run">
        <div>
            <strong><?= e(count($preview['ready'])) ?> recipient(s)</strong> · about <?= e($preview['segments']) ?> SMS part(s) in total.
            <?php $ex = array_filter($preview['excluded']); if ($ex): ?>
                <div class="muted small">Left out: <?= e(implode(', ', array_map(static fn ($k, $n) => "{$n} {$exLabels[$k]}", array_keys($ex), $ex))) ?>.</div>
            <?php endif; ?>
        </div>
        <div class="form-actions">
            <form method="post" action="<?= e(url("sms/campaigns/{$c['id']}/start")) ?>" data-confirm="<?= e(($c['scheduled_at'] ? 'Schedule ' : 'Send ') . count($preview['ready']) . ' SMS' . ($testMode ? ' (test mode)' : '') . '?') ?>">
                <?= Csrf::field() ?><button type="submit" class="btn btn-primary"<?= $preview['ready'] === [] ? ' disabled' : '' ?>><?= $c['scheduled_at'] ? 'Schedule' : 'Send now' ?></button>
            </form>
            <form method="post" action="<?= e(url("sms/campaigns/{$c['id']}/cancel")) ?>"><?= Csrf::field() ?><button type="submit" class="btn">Discard</button></form>
        </div>
    </section>
    <section class="card table-card">
        <h2>First messages</h2>
        <?php if ($preview['ready'] === []): ?><p class="empty">Nobody matches.</p><?php else: ?>
        <div class="table-scroll">
        <table class="table compact">
            <thead><tr><th>To</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($preview['ready'], 0, 10) as $r): ?>
                <tr><td class="small nowrap"><?= e($r['name']) ?><div class="muted"><?= e($r['mobile']) ?></div></td><td class="small"><?= e($r['message']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="import-stats">
        <div class="card stat"><span>Recipients</span><strong><?= e($c['total_recipients']) ?></strong></div>
        <a class="card stat stat-ok" href="<?= e(url('sms') . '?campaign=' . $c['id'] . '&status=delivered') ?>"><span>Sent / delivered</span><strong><?= e($c['sent_count']) ?></strong></a>
        <a class="card stat stat-fail" href="<?= e(url('sms') . '?campaign=' . $c['id'] . '&status=failed') ?>"><span>Failed</span><strong><?= e($c['failed_count']) ?></strong></a>
        <a class="card stat" href="<?= e(url('sms') . '?campaign=' . $c['id'] . '&status=queued') ?>"><span>Waiting</span><strong><?= e(($byStatus['queued'] ?? 0) + ($byStatus['pending'] ?? 0)) ?></strong></a>
    </section>
    <p><a class="btn" href="<?= e(url('sms') . '?campaign=' . $c['id']) ?>">See every message</a></p>
    <?php if (in_array($c['status'], ['scheduled', 'processing'], true)): ?>
        <form method="post" action="<?= e(url("sms/campaigns/{$c['id']}/cancel")) ?>" data-confirm="Stop this campaign? Messages not yet sent will be cancelled.">
            <?= Csrf::field() ?><button type="submit" class="btn">Stop campaign</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
