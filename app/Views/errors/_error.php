<?php
/** @var int $code */
/** @var string $heading */
/** @var string $message */
/** @var string|null $ref */
/** @var string|null $detail */
$title = $code . ' · ' . $heading;
ob_start();
?>
<section class="card error-card">
    <p class="error-code"><?= e($code) ?></p>
    <h1><?= e($heading) ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <?php if (!empty($ref)): ?>
        <p class="muted small">Reference: <code><?= e($ref) ?></code></p>
    <?php endif; ?>
    <?php if (!empty($detail)): ?>
        <pre class="debug"><?= e($detail) ?></pre>
    <?php endif; ?>
    <div class="actions"><a class="btn btn-primary" href="<?= e(url('/')) ?>">Go to home</a></div>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/minimal.php';
