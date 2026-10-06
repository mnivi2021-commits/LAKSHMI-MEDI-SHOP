<?php
/* Public introduction / reference page shown to visitors who are not signed in. */
ob_start();
?>
<section class="intro-hero">
    <p class="eyebrow">Management information system</p>
    <h1>Every sales number, one trusted place.</h1>
    <p class="lead">Marketing CRM brings sales targets, payment collection, pending orders, samples, delivery challans,
        customer mail and SMS together, branch by branch and sales representative by sales representative.
        Every figure on the dashboard can be opened down to the invoice, receipt or order behind it.</p>
    <div class="actions">
        <a class="btn btn-primary btn-lg" href="<?= e(url('login')) ?>">Sign in to the CRM</a>
    </div>
</section>

<section class="feature-grid" aria-label="What the CRM covers">
    <article class="card feature">
        <h2>Sales performance</h2>
        <p class="muted">Annual target, sales up to yesterday, this month and today, target achieved and the monthly run-rate still needed.</p>
    </article>
    <article class="card feature">
        <h2>Payment collection</h2>
        <p class="muted">Today's and this month's receipts, collection against target, overdue bills and 90 / 150-day outstanding.</p>
    </article>
    <article class="card feature">
        <h2>Pending orders</h2>
        <p class="muted">Order value still to supply, by branch and customer, with 0-30 to 150+ day ageing.</p>
    </article>
    <article class="card feature">
        <h2>Sales representatives</h2>
        <p class="muted">One screen per representative: sales, collection, pending orders, samples, DC and overdue payments.</p>
    </article>
    <article class="card feature">
        <h2>Samples &amp; DC</h2>
        <p class="muted">Samples awaiting customer decision and goods out on delivery challan, not yet invoiced.</p>
    </article>
    <article class="card feature">
        <h2>Mail, SMS &amp; leads</h2>
        <p class="muted">Daily counts of enquiries, orders, new leads and payment advice; follow-ups and SMS from one place.</p>
    </article>
</section>

<section class="card access-ways" aria-label="Ways to use the CRM">
    <h2>Three ways to use it</h2>
    <div class="ways">
        <div class="way">
            <span class="way-num">1</span>
            <div><h3>Office computer</h3><p class="muted small">Open the CRM in a browser on the main office PC.</p></div>
        </div>
        <div class="way">
            <span class="way-num">2</span>
            <div><h3>Any device on office Wi-Fi</h3><p class="muted small">Phones, tablets and laptops on the same Wi-Fi open this same address in their browser.</p></div>
        </div>
        <div class="way">
            <span class="way-num">3</span>
            <div><h3>Mobile app</h3><p class="muted small">Sales representatives see their own performance, customers and follow-ups on their phone:
                the Marketing CRM app, or <a href="<?= e(url('app/')) ?>">open it in the phone's browser</a>.</p></div>
        </div>
    </div>
</section>

<p class="muted small center">Access is limited to authorised staff. Every sign-in is recorded.</p>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/minimal.php';
