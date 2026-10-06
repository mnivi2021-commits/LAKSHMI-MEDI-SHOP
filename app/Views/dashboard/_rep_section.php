<?php
/**
 * Step B - SALES REPRESENTATIVE PERFORMANCE: selectable rep boxes + the selected rep's panel.
 * @var \App\Modules\Dashboard\DashboardContext $ctx
 * @var array<int, string> $employees
 * @var array<string, mixed>|null $rep  DashboardController::repPanel()
 */
use App\Core\Gate;

$selectedId = $rep['employee']['id'] ?? null;
$repUrl = static fn (?int $id): string => url('/') . '?' . $ctx->query(['employee' => $id]) . '#rep';
$withRep = static fn (string $path, array $extra = []) => url($path) . '?' . ($rep ? $rep['query'] : $ctx->query()) . ($extra ? '&' . http_build_query($extra) : '');
?>
<section aria-labelledby="sec-b" id="rep">
    <h2 id="sec-b" class="section-title">Sales representative performance</h2>
    <div class="card rep-strip">
        <?php if ($employees === []): ?>
            <p class="muted">No sales representatives in your view.</p>
        <?php else: ?>
            <?php foreach ($employees as $id => $lbl): $short = strtok($lbl, ' '); ?>
                <a class="rep-chip<?= $id === $selectedId ? ' selected' : '' ?>" href="<?= e($repUrl($id)) ?>"<?= $id === $selectedId ? ' aria-current="true"' : '' ?>><?= e($short) ?></a>
            <?php endforeach; ?>
            <?php if ($selectedId === null): ?><p class="muted small rep-note">Select a representative to see their full performance for this period.</p><?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($rep !== null):
        $e = $rep['employee']; $s = $rep['sales']; $c = $rep['collection']; $p = $rep['pending']; $sd = $rep['sampleDc']; $o = $rep['outstanding']; $l = $rep['leads'];
    ?>
    <div class="card rep-panel">
        <header class="rep-head">
            <div>
                <p class="eyebrow">Selected</p>
                <h3 class="rep-name"><?= e(mb_strtoupper($e['short_name'] ?: $e['name'])) ?></h3>
                <p class="muted small"><?= e($e['name']) ?> · <?= e($e['designation'] ?? 'Sales') ?> · <?= e($e['branch']) ?> (<?= e($e['branch_code']) ?>)
                    <?= $e['manager'] ? ' · reports to ' . e($e['manager']) : '' ?><?= $e['mobile'] ? ' · ' . e($e['mobile']) : '' ?></p>
            </div>
            <?php if (count($employees) > 1): ?><a class="btn btn-sm" href="<?= e($repUrl(null)) ?>">Clear selection</a><?php endif; ?>
        </header>

        <div class="rep-grid">
            <section class="rep-box">
                <h4><?= Gate::allows('sales.view') ? '<a href="' . e($withRep('dashboard/sales')) . '">Sales</a>' : 'Sales' ?></h4>
                <dl>
                    <div><dt><?= e($s['month_name']) ?> sales</dt><dd><?= e(rupees($s['month_to_date'])) ?></dd></div>
                    <div><dt>Year to date</dt><dd><?= e(rupees($s['sales_total'])) ?></dd></div>
                    <div><dt>Annual target</dt><dd><?= e(rupees($s['annual_target'])) ?></dd></div>
                    <div><dt>Target achieved</dt><dd><?= $s['achieved_pct'] === null ? '—' : e(number_format($s['achieved_pct'], 1)) . '%' ?></dd></div>
                    <div><dt>Target pending</dt><dd><?= e(rupees($s['target_pending'])) ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4><?= Gate::allows('collections.view') ? '<a href="' . e($withRep('dashboard/collection')) . '">Collection</a>' : 'Collection' ?></h4>
                <dl>
                    <div><dt><?= e($c['month_name']) ?> collection</dt><dd><?= e(rupees($c['total'])) ?></dd></div>
                    <div><dt>Year to date</dt><dd><?= e(rupees($c['fy_to_date'])) ?></dd></div>
                    <div><dt>Pending collection</dt><dd><?= e(rupees($c['collection_pending'])) ?></dd></div>
                    <div><dt>Overdue collection</dt><dd class="<?= ($c['overdue'] ?? 0) > 0 ? 'bad' : '' ?>"><?= e(rupees($c['overdue'])) ?></dd></div>
                </dl>
            </section>
            <section class="rep-box">
                <h4><?= Gate::allows('pending_orders.view') ? '<a href="' . e($withRep('dashboard/pending')) . '">Pending order</a>' : 'Pending order' ?></h4>
                <dl>
                    <div><dt>Pending value</dt><dd><?= e(rupees($p['value'])) ?></dd></div>
                    <div><dt>Orders · customers</dt><dd><?= e($p['orders']) ?> · <?= e($p['customers']) ?></dd></div>
                    <div><dt>Oldest</dt><dd><?= $p['oldest'] ? e($p['oldest']['age']) . ' days' : '—' ?></dd></div>
                    <?php foreach ($p['aging'] as $b): if ($b['value'] === 0) { continue; } ?>
                        <div><dt class="sub"><?= e($b['label']) ?></dt><dd><?= e(rupees($b['value'])) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </section>
            <section class="rep-box">
                <h4>Sample / DC supply</h4>
                <dl>
                    <div><dt>Pending samples</dt><dd><?= e($sd['samples']['documents']) ?></dd></div>
                    <div><dt>Sample value</dt><dd><?= e(rupees($sd['samples']['value'])) ?></dd></div>
                    <div><dt>Pending DC</dt><dd><?= e($sd['dc']['documents']) ?></dd></div>
                    <div><dt>DC value</dt><dd><?= e(rupees($sd['dc']['value'])) ?></dd></div>
                    <div><dt>Customers (sample · DC)</dt><dd><?= e($sd['samples']['customers']) ?> · <?= e($sd['dc']['customers']) ?></dd></div>
                </dl>
            </section>
        </div>

        <section class="rep-overdue">
            <h4>Payment overdue</h4>
            <div class="overdue-grid">
                <?php foreach ($o as $key => $cat): ?>
                    <a class="overdue-box overdue-<?= e($key) ?>" href="<?= e($withRep('dashboard/outstanding', ['cat' => $key])) ?>">
                        <span class="overdue-label"><?= e($cat['label']) ?></span>
                        <strong><?= e(rupees($cat['value'])) ?></strong>
                        <span class="small"><?= e($cat['customers']) ?> customer(s) · <?= e($cat['bills']) ?> bill(s)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <p class="rep-leads small">
            <strong><?= e($l['new_this_month']) ?></strong> new lead(s) this month ·
            <strong><?= e($l['open_leads']) ?></strong> open lead(s) ·
            <strong class="<?= $l['followups_due'] > 0 ? 'bad' : '' ?>"><?= e($l['followups_due']) ?></strong> follow-up(s) due
        </p>
    </div>
    <?php endif; ?>
</section>
