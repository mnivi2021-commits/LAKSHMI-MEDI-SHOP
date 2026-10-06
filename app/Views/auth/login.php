<?php
/** @var string $identifier */
/** @var string $next */
use App\Core\Csrf;

$hideSignIn = true;
ob_start();
?>
<section class="auth-card card">
    <h1>Sign in</h1>
    <p class="muted">Use your CRM username or email address.</p>

    <?php require dirname(__DIR__) . '/partials/flash.php'; ?>

    <form method="post" action="<?= e(url('login')) ?>" class="form" novalidate>
        <?= Csrf::field() ?>
        <?php if ($next !== ''): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>

        <label class="field">
            <span>Username or email</span>
            <input type="text" name="identifier" value="<?= e($identifier) ?>" autocomplete="username"
                   autocapitalize="none" spellcheck="false" maxlength="150" required autofocus>
        </label>

        <label class="field">
            <span>Password</span>
            <span class="password-wrap">
                <input type="password" name="password" autocomplete="current-password" maxlength="200" required>
                <button type="button" class="btn-link" data-toggle-password aria-label="Show password">Show</button>
            </span>
        </label>

        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="muted small">Forgot your password? Ask the Admin Head to reset it.</p>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/minimal.php';
