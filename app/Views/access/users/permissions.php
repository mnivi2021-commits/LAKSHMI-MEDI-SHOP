<?php
/** @var array<string, mixed> $user */
/** @var array<string, array{label: string, items: array<string, array{id: int, slug: string, critical: bool}>}> $catalogue */
/** @var list<string> $columns */
/** @var array<string, true> $roleSlugs */
/** @var array<int, string> $overrides */
/** @var array<string, true> $actorHolds */
/** @var bool $isSelf */
use App\Core\Csrf;

ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('access/users')) ?>">Users</a></p>
        <h1>Permission overrides · <?= e($user['name']) ?></h1>
        <p class="muted">Role: <strong><?= e($user['role_name']) ?></strong>. Overrides apply to this user only, on top of the role.</p>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/partials/flash.php'; ?>

<div class="legend card">
    <span><span class="dot dot-role"></span> From role</span>
    <span><span class="dot dot-grant"></span> Extra grant</span>
    <span><span class="dot dot-deny"></span> Denied</span>
    <span class="muted small">Change only the exceptions. Leave "Role" for everything else.</span>
</div>

<form method="post" action="<?= e(url("access/users/{$user['id']}/permissions")) ?>" class="card table-card">
    <?= Csrf::field() ?>
    <div class="table-scroll">
    <table class="table overrides">
        <thead><tr><th>Module</th><th>Permission</th><th>Role gives</th><th>This user</th></tr></thead>
        <tbody>
        <?php foreach ($catalogue as $module => $group): ?>
            <?php $first = true; foreach ($group['items'] as $action => $perm):
                $fromRole = isset($roleSlugs[$perm['slug']]);
                $current = $overrides[$perm['id']] ?? '';
                $locked = $isSelf || !isset($actorHolds[$perm['slug']]);
            ?>
            <tr>
                <td><?= $first ? '<strong>' . e($group['label']) . '</strong>' : '' ?></td>
                <td><?= e(ucfirst($action)) ?><?= $perm['critical'] ? ' <span class="badge badge-warn" title="Critical permission">critical</span>' : '' ?></td>
                <td><?= $fromRole ? '<span class="badge badge-ok">Yes</span>' : '<span class="muted">No</span>' ?></td>
                <td>
                    <select name="override[<?= e($perm['id']) ?>]"<?= $locked ? ' disabled' : '' ?> class="override-select">
                        <option value="">Role (<?= $fromRole ? 'allowed' : 'not allowed' ?>)</option>
                        <option value="grant"<?= $current === 'grant' ? ' selected' : '' ?>>Grant</option>
                        <option value="deny"<?= $current === 'deny' ? ' selected' : '' ?>>Deny</option>
                    </select>
                    <?php if ($locked && $current !== ''): ?>
                        <input type="hidden" name="override[<?= e($perm['id']) ?>]" value="<?= e($current) ?>">
                    <?php endif; ?>
                </td>
            </tr>
            <?php $first = false; endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="form-actions padded">
        <button type="submit" class="btn btn-primary"<?= $isSelf ? ' disabled' : '' ?>>Save overrides</button>
        <a class="btn" href="<?= e(url('access/users')) ?>">Back to users</a>
    </div>
</form>
<?php
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/layouts/app.php';
