<?php
/** @var \App\Modules\Dashboard\DashboardContext $ctx */
/** @var string $kind */
/** @var array{rows: list<array<string, mixed>>, total: int, count: int} $docs */
/** @var int $page */
/** @var int $pages */
$label = $kind === 'dc' ? 'Pending DC' : 'Pending samples';
ob_start();
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= e(url('sales') . '?' . $ctx->query()) ?>">Sales Details</a></p>
        <h1><?= e($label) ?></h1>
        <p class="muted small">As on <?= e($ctx->asOn->format('d-m-Y')) ?> · <?= e($docs['count']) ?> document<?= $docs['count'] === 1 ? '' : 's' ?> · <?= e(rupees($docs['total'])) ?></p>
    </div>
</div>

<section class="card table-card">
    <?php if ($docs['rows'] === []): ?>
        <p class="empty">Nothing pending for these filters.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Date</th><th>Document</th><th>Customer</th><th>Products</th><th>Branch</th><th>Employee</th><th class="right">Age (days)</th><th class="right">Value</th></tr></thead>
        <tbody>
        <?php foreach ($docs['rows'] as $d): ?>
            <tr>
                <td class="nowrap small"><?= e(date('d-m-Y', strtotime($d['document_date']))) ?></td>
                <td><?= e($d['document_no']) ?></td>
                <td class="small"><?= e($d['customer']) ?> <span class="muted"><?= e($d['customer_code']) ?></span></td>
                <td class="small"><?= e($d['products']) ?></td>
                <td class="small"><?= e($d['branch_code']) ?></td>
                <td class="small"><?= e($d['employee'] ?? '—') ?></td>
                <td class="right num"><?= e($d['age']) ?></td>
                <td class="right num"><?= e(rupees(\App\Core\Money::fromDb($d['value']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="total-row"><th colspan="7">Total</th><th class="right num"><?= e(rupees($docs['total'])) ?></th></tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($ctx->query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($ctx->query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layouts/app.php';
