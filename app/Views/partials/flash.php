<?php
/** @var list<array{type: string, message: string}> $flash */
// Loop variable is deliberately unusual: partials share the including view's scope.
foreach ($flash ?? [] as $__flashItem): ?>
    <div class="alert alert-<?= e($__flashItem['type']) ?>" role="<?= $__flashItem['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($__flashItem['message']) ?></div>
<?php endforeach;
unset($__flashItem);
