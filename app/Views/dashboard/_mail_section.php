<?php
/** @var array{cards: list<array<string, mixed>>, as_on: string, month_name: string, note: ?string} $mail */
?>
<section aria-labelledby="sec-mail">
    <h2 id="sec-mail" class="section-title">Email</h2>
    <?php if ($mail['note']): ?><p class="muted small"><?= e($mail['note']) ?></p><?php endif; ?>
    <div class="mail-cards">
        <?php foreach ($mail['cards'] as $c): ?>
            <article class="card mail-card mail-<?= e($c['color']) ?>">
                <a class="mail-card-main" href="<?= e(url('mail') . '?' . $c['href_month']) ?>" aria-label="<?= e($c['name']) ?> emails in <?= e($mail['month_name']) ?>">
                    <span class="mail-card-name"><?= e(strtoupper($c['name'])) ?></span>
                    <strong class="mail-card-count"><?= e($c['month']) ?></strong>
                    <span class="muted small"><?= e($mail['month_name']) ?> to date</span>
                </a>
                <div class="mail-card-foot small">
                    <a href="<?= e(url('mail') . '?' . $c['href_day']) ?>">As-on day <strong><?= e($c['day']) ?></strong></a>
                    <a href="<?= e(url('mail') . '?' . $c['href_open']) ?>">Open <strong><?= e($c['open']) ?></strong></a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
