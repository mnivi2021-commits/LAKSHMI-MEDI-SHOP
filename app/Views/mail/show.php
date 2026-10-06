<?php
/** @var array<string, mixed> $email */
/** @var list<array<string, mixed>> $categories */
/** @var array<string, mixed>|null $customer */
/** @var array<string, mixed>|null $lead */
/** @var array<string, mixed>|null $assignee */
/** @var list<array<string, mixed>> $activity */
/** @var list<array<string, mixed>> $attachments */
/** @var list<array<string, mixed>> $users */
/** @var list<array<string, mixed>> $branches */
/** @var array<string, mixed>|null $account */
use App\Core\Csrf;
use App\Modules\Mail\Classifier;
use App\Modules\Mail\MailService;

$byId = array_column($categories, null, 'id');
$current = $byId[$email['category_id']] ?? null;
$auto = $byId[$email['auto_category_id']] ?? null;
$unsure = $email['classification_method'] !== 'manual' && (float) ($email['classification_confidence'] ?? 0) < Classifier::REVIEW_BELOW;
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('mail')) ?>">Mail</a></p>
        <h1><?= e($email['subject'] ?: '(no subject)') ?></h1>
        <p class="muted small">From <strong><?= e($email['from_name'] ?: $email['from_email']) ?></strong> &lt;<?= e($email['from_email']) ?>&gt;
            · <?= e(date('d-m-Y H:i', strtotime($email['received_at']))) ?> · to <?= e($account['email_address'] ?? '') ?></p>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<div class="mail-layout">
    <div>
        <section class="card padded-card">
            <p class="mail-body"><?= nl2br(e($email['body_preview'] ?: '(no text)')) ?></p>
            <?php if ($attachments): ?>
                <p class="muted small">Attachments: <?= e(implode(', ', array_map(static fn ($a) => $a['original_name'] . ($a['size_bytes'] ? ' (' . round($a['size_bytes'] / 1024) . ' KB)' : ''), $attachments))) ?></p>
            <?php endif; ?>
            <p class="muted small">Only a short text preview is kept in the CRM; open the original in your mailbox for the full message.</p>
        </section>

        <section class="card table-card">
            <h2>History</h2>
            <?php if ($activity === []): ?><p class="empty">No changes recorded yet.</p><?php endif; ?>
            <ul class="timeline padded">
                <?php foreach ($activity as $a): ?>
                    <li><span class="muted small"><?= e(date('d-m-Y H:i', strtotime($a['created_at']))) ?> · <?= e($a['user_name'] ?? 'System') ?></span>
                        <div><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?><?= $a['old_value'] !== null || $a['new_value'] !== null ? ': ' . e($a['old_value'] ?? '—') . ' → ' . e($a['new_value'] ?? '—') : '' ?>
                        <?= $a['note'] ? '<div class="muted small">' . e($a['note']) . '</div>' : '' ?></div></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <aside>
        <section class="card padded-card">
            <h2>Category</h2>
            <p><span class="badge mail-badge mail-<?= e($current['color'] ?? 'slate') ?>"><?= e($current['name'] ?? '—') ?></span>
                <?php if ($email['classification_method'] === 'manual'): ?>
                    <span class="muted small">corrected by hand<?= $auto && $auto['id'] !== $current['id'] ? ' (rules said ' . e($auto['name']) . ')' : '' ?></span>
                <?php elseif ($email['classification_method'] === 'rule'): ?>
                    <span class="small<?= $unsure ? ' neg' : ' muted' ?>"><?= e(number_format((float) $email['classification_confidence'], 0)) ?>% sure<?= $unsure ? ' – please check' : '' ?></span>
                <?php else: ?><span class="small neg">no rule matched – please check</span><?php endif; ?></p>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url("mail/{$email['id']}/category")) ?>" class="inline-form">
                    <?= Csrf::field() ?>
                    <select name="category_id" aria-label="Category">
                        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"<?= (int) $c['id'] === (int) $email['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm">Correct</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card padded-card">
            <h2>Status</h2>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url("mail/{$email['id']}/status")) ?>" class="stack-form">
                    <?= Csrf::field() ?>
                    <label class="field"><span>Status</span><select name="status">
                        <?php foreach (MailService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $email['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Follow-up</span><select name="followup_status">
                        <?php foreach (['none' => 'Not needed', 'pending' => 'Pending', 'done' => 'Done'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $email['followup_status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
                    <button type="submit" class="btn btn-sm">Save</button>
                </form>
            <?php else: ?>
                <p><?= e(MailService::STATUSES[$email['status']]) ?> · follow-up <?= e($email['followup_status']) ?></p>
            <?php endif; ?>
        </section>

        <section class="card padded-card">
            <h2>Assigned to</h2>
            <p><?= $assignee ? e($assignee['name']) . ' <span class="muted small">since ' . e(date('d-m-Y', strtotime($assignee['created_at']))) . '</span>' : '<span class="muted">Nobody yet</span>' ?></p>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url("mail/{$email['id']}/assign")) ?>" class="stack-form">
                    <?= Csrf::field() ?>
                    <select name="user_id" aria-label="Assign to">
                        <?php foreach ($users as $u): ?><option value="<?= e($u['id']) ?>"<?= $assignee && (int) $assignee['id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                    </select>
                    <input type="text" name="note" maxlength="500" placeholder="Note (optional)" aria-label="Note">
                    <button type="submit" class="btn btn-sm">Assign</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card padded-card">
            <h2>Customer / lead</h2>
            <?php if ($customer): ?>
                <p><a href="<?= e(url("customers/{$customer['id']}")) ?>"><?= e($customer['name']) ?></a> <span class="muted small"><?= e($customer['customer_code']) ?></span></p>
            <?php elseif ($lead): ?>
                <p>Lead <a href="<?= e(url("leads/{$lead['id']}")) ?>"><?= e($lead['lead_number']) ?></a> · <?= e($lead['name']) ?></p>
            <?php else: ?>
                <p class="muted small">Sender is not a known customer or lead.</p>
                <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url("mail/{$email['id']}/customer")) ?>" class="inline-form">
                        <?= Csrf::field() ?><input type="text" name="customer_code" maxlength="30" placeholder="Customer code" aria-label="Customer code" class="uppercase">
                        <button type="submit" class="btn btn-sm">Link</button>
                    </form>
                <?php endif; ?>
                <?php if ($canLead): ?>
                    <form method="post" action="<?= e(url("mail/{$email['id']}/lead")) ?>" class="inline-form mt">
                        <?= Csrf::field() ?>
                        <select name="branch_id" aria-label="Branch for the lead">
                            <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= (int) $b['id'] === (int) $email['branch_id'] ? ' selected' : '' ?>><?= e($b['branch_code']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Create lead</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </aside>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
