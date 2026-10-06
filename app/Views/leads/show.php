<?php
/** @var array<string, mixed> $lead */
/** @var list<array<string, mixed>> $followups */
/** @var list<array<string, mixed>> $history */
/** @var bool $canEdit */
/** @var bool $canDelete */
/** @var bool $canConvert */
use App\Core\Csrf;
use App\Core\Money;
use App\Modules\Leads\LeadController;

$l = $lead;
$closed = in_array($l['status'], ['won', 'lost'], true);
$statusBadge = ['won' => 'badge-ok', 'lost' => 'badge-fail', 'new' => 'badge-muted'];
$fuBadge = ['completed' => 'badge-ok', 'missed' => 'badge-fail', 'cancelled' => 'badge-muted', 'pending' => 'badge-warn', 'rescheduled' => 'badge-muted'];
$now = date('Y-m-d H:i:s');
$actionLabels = [
    'lead.created' => 'Lead created', 'lead.updated' => 'Details updated', 'lead.status_changed' => 'Status changed',
    'lead.followup_scheduled' => 'Follow-up scheduled', 'lead.followup_completed' => 'Follow-up completed',
    'lead.followup_missed' => 'Follow-up missed', 'lead.converted' => 'Converted to customer',
];
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('leads')) ?>">Leads</a> / <?= e($l['lead_number']) ?></p>
        <h1><?= e($l['name']) ?></h1>
        <p class="muted small"><?= $l['company_name'] ? e($l['company_name']) . ' · ' : '' ?>
            <span class="badge <?= e($statusBadge[$l['status']] ?? 'badge-info') ?>"><?= e(LeadController::STATUSES[$l['status']]) ?></span>
            <span class="badge badge-muted"><?= e(LeadController::PRIORITIES[$l['priority']]) ?> priority</span></p>
    </div>
    <div class="form-actions">
        <?php if ($canEdit): ?><a class="btn" href="<?= e(url("leads/{$l['id']}/edit")) ?>">Edit</a><?php endif; ?>
        <?php if ($canConvert && $l['customer_id'] === null && $l['status'] !== 'lost'): ?>
            <form method="post" action="<?= e(url("leads/{$l['id']}/convert")) ?>" class="inline-form" data-confirm="Create a customer from this lead and mark it Won?">
                <?= Csrf::field() ?><button type="submit" class="btn btn-primary">Convert to customer</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<?php if ($l['customer_id'] !== null): ?>
    <div class="alert alert-success">Converted to customer <a href="<?= e(url("customers/{$l['customer_id']}")) ?>"><?= e($l['customer_name']) ?> (<?= e($l['customer_code']) ?>)</a>.</div>
<?php endif; ?>

<div class="split">
    <section class="card">
        <h2>Details</h2>
        <dl class="kpi-lines">
            <div><dt>Mobile</dt><dd><?= e($l['mobile'] ?? '—') ?></dd></div>
            <div><dt>Email</dt><dd><?= e($l['email'] ?? '—') ?></dd></div>
            <div><dt>Source</dt><dd><?= e($l['source_name'] ?? '—') ?></dd></div>
            <div><dt>Product</dt><dd><?= e($l['product_name'] ?? '—') ?></dd></div>
            <div><dt>Branch</dt><dd><?= e($l['branch_name']) ?> (<?= e($l['branch_code']) ?>)</dd></div>
            <div><dt>Sales employee</dt><dd><?= e($l['employee_name'] ?? 'Unassigned') ?></dd></div>
            <div><dt>Expected value</dt><dd><?= $l['expected_value'] !== null ? e(rupees(Money::fromDb($l['expected_value']))) : '—' ?></dd></div>
            <div><dt>Next follow-up</dt><dd class="<?= $l['next_followup_at'] && $l['next_followup_at'] < $now && !$closed ? 'bad' : '' ?>"><?= $l['next_followup_at'] ? e(date('d-m-Y H:i', strtotime($l['next_followup_at']))) : '—' ?></dd></div>
            <div><dt>Created</dt><dd><?= e(date('d-m-Y', strtotime($l['created_at']))) ?></dd></div>
            <?php if ($l['status'] === 'lost' && $l['lost_reason']): ?><div><dt>Lost reason</dt><dd><?= e($l['lost_reason']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($l['remarks']): ?><p class="muted small"><?= nl2br(e($l['remarks'])) ?></p><?php endif; ?>
    </section>

    <?php if ($canEdit): ?>
    <section class="card">
        <h2>Change status</h2>
        <?php if ($l['customer_id'] !== null): ?>
            <p class="muted small">Converted leads stay Won.</p>
        <?php else: ?>
        <form method="post" action="<?= e(url("leads/{$l['id']}/status")) ?>" class="form">
            <?= Csrf::field() ?>
            <label class="field">
                <span>Status</span>
                <select name="status">
                    <?php foreach (LeadController::STATUSES as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $l['status'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Reason (required for Lost)</span>
                <input type="text" name="lost_reason" maxlength="255" value="<?= e($l['lost_reason'] ?? '') ?>" placeholder="e.g. price too high, chose competitor">
            </label>
            <button type="submit" class="btn btn-primary">Save status</button>
        </form>
        <?php endif; ?>

        <?php if (!$closed): ?>
        <h2 class="mt">Schedule follow-up</h2>
        <form method="post" action="<?= e(url("leads/{$l['id']}/followups")) ?>" class="form">
            <?= Csrf::field() ?>
            <label class="field">
                <span>When *</span>
                <input type="datetime-local" name="followup_at" required value="<?= e(date('Y-m-d\TH:i', strtotime('+1 day 10:00'))) ?>">
            </label>
            <label class="field">
                <span>Type</span>
                <select name="followup_type">
                    <?php foreach (LeadController::FOLLOWUP_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Notes</span>
                <input type="text" name="notes" maxlength="1000" placeholder="What to discuss">
            </label>
            <button type="submit" class="btn">Schedule</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<section class="card table-card">
    <h2>Follow-ups</h2>
    <?php if ($followups === []): ?>
        <p class="empty">No follow-ups yet.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>When</th><th>Type</th><th>Notes</th><th>Status</th><th>Outcome</th><th class="right">Action</th></tr></thead>
        <tbody>
        <?php foreach ($followups as $f): $late = $f['status'] === 'pending' && $f['followup_at'] < $now; ?>
            <tr>
                <td class="nowrap<?= $late ? ' neg' : '' ?>"><?= e(date('d-m-Y H:i', strtotime($f['followup_at']))) ?><?= $late ? ' (due)' : '' ?></td>
                <td><?= e(LeadController::FOLLOWUP_TYPES[$f['followup_type']] ?? $f['followup_type']) ?></td>
                <td class="small"><?= e($f['notes'] ?? '') ?></td>
                <td><span class="badge <?= e($fuBadge[$f['status']] ?? 'badge-muted') ?>"><?= e(ucfirst($f['status'])) ?></span></td>
                <td class="small"><?= e($f['outcome'] ?? '') ?></td>
                <td class="right">
                    <?php if ($canEdit && $f['status'] === 'pending'): ?>
                        <details class="row-menu">
                            <summary class="btn btn-sm">Record</summary>
                            <div class="row-menu-panel fu-panel">
                                <form method="post" action="<?= e(url("leads/{$l['id']}/followups/{$f['id']}/complete")) ?>" class="form">
                                    <?= Csrf::field() ?>
                                    <input type="text" name="outcome" maxlength="1000" placeholder="Outcome">
                                    <button type="submit" name="result" value="completed">Done</button>
                                    <button type="submit" name="result" value="missed" class="danger">Missed</button>
                                </form>
                            </div>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card table-card">
    <h2>History</h2>
    <?php if ($history === []): ?><p class="empty">No history.</p><?php else: ?>
    <div class="table-scroll"><table class="table compact">
        <thead><tr><th>When</th><th>What</th><th>By</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): $new = json_decode((string) $h['new_data'], true) ?: []; $old = json_decode((string) $h['old_data'], true) ?: []; ?>
            <tr>
                <td class="nowrap small"><?= e(date('d-m-Y H:i', strtotime($h['created_at']))) ?></td>
                <td><?= e($actionLabels[$h['action']] ?? $h['action']) ?></td>
                <td class="small"><?= e($h['user_name'] ?? '—') ?></td>
                <td class="small muted"><?php
                    if ($h['action'] === 'lead.status_changed') {
                        echo e((LeadController::STATUSES[$old['status'] ?? ''] ?? '?') . ' → ' . (LeadController::STATUSES[$new['status'] ?? ''] ?? '?') . (isset($new['lost_reason']) ? ' (' . $new['lost_reason'] . ')' : ''));
                    } elseif (isset($new['at'])) {
                        echo e($new['at'] . ' · ' . ($new['type'] ?? ''));
                    } elseif (isset($new['outcome']) && $new['outcome'] !== '') {
                        echo e($new['outcome']);
                    } elseif ($h['action'] === 'lead.updated') {
                        echo e(implode(', ', array_keys($new)));
                    }
                ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($canDelete && $l['customer_id'] === null): ?>
    <form method="post" action="<?= e(url("leads/{$l['id']}/delete")) ?>" data-confirm="Delete lead <?= e($l['lead_number']) ?>?">
        <?= Csrf::field() ?><button type="submit" class="btn btn-sm btn-danger">Delete lead</button>
    </form>
<?php endif; ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
