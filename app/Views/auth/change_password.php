<?php
/** @var array<string, mixed> $user */
/** @var bool $forced */
/** @var int $minLength */
use App\Core\Csrf;

ob_start();
?>
<section class="auth-card card">
    <h1><?= $forced ? 'Set a new password' : 'Change password' ?></h1>
    <p class="muted">
        <?= $forced
            ? 'Your account uses a temporary password. Choose your own password to continue.'
            : 'Changing your password signs you out on every other device.' ?>
    </p>

    <?php require dirname(__DIR__) . '/partials/flash.php'; ?>

    <form method="post" action="<?= e(url('password/change')) ?>" class="form" novalidate>
        <?= Csrf::field() ?>
        <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden readonly>

        <label class="field">
            <span><?= $forced ? 'Temporary password' : 'Current password' ?></span>
            <input type="password" name="current_password" autocomplete="current-password" maxlength="200" required>
        </label>

        <label class="field">
            <span>New password</span>
            <span class="password-wrap">
                <input type="password" name="new_password" autocomplete="new-password" minlength="<?= e($minLength) ?>" maxlength="72" required>
                <button type="button" class="btn-link" data-toggle-password aria-label="Show password">Show</button>
            </span>
        </label>

        <label class="field">
            <span>Confirm new password</span>
            <input type="password" name="confirm_password" autocomplete="new-password" maxlength="72" required>
        </label>

        <ul class="hint-list muted small">
            <li>At least <?= e($minLength) ?> characters, with letters and numbers</li>
            <li>Not your username, and not a common password</li>
        </ul>

        <button type="submit" class="btn btn-primary btn-block">Save new password</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
