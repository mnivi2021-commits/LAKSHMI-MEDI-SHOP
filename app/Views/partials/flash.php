<?php
/** @var list<array{type: string, message: string}> $flash */
foreach ($flash ?? [] as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
<?php endforeach;
