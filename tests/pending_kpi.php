<?php

declare(strict_types=1);

/*
 * Step A3 Branch Pending Order - accuracy tests:  php tests/pending_kpi.php
 * Compared with independent SQL on base tables. Seeded DB; rolled back. Today = 06-10-2026.
 */

use App\Core\Database;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

/** Independent pending: base tables only. */
function rawPending(string $asOn, string $extra = '', array $p = []): array
{
    $r = Database::fetch(
        "SELECT COALESCE(SUM(i.order_value - i.supplied_value), 0) AS v, COUNT(DISTINCT o.id) AS orders, COUNT(DISTINCT o.customer_id) AS customers
         FROM pending_orders o JOIN pending_order_items i ON i.order_id = o.id
         WHERE o.status IN ('open','partial') AND o.deleted_at IS NULL AND i.order_value > i.supplied_value AND o.order_date <= ? {$extra}",
        array_merge([$asOn], $p)
    );
    return [Money::fromDb($r['v']), (int) $r['orders'], (int) $r['customers']];
}

$today = new DateTimeImmutable('2026-10-06');
$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $admin = seeded_user('admin');
    $jana = seeded_user('jana');
    $kpi = static fn (array $u, array $q = []): PendingOrderKpi => new PendingOrderKpi(DashboardContext::fromRequest($u, $q, $today));

    $s = $kpi($admin)->summary();
    [$v, $o, $c] = rawPending('2026-10-06');
    check('total pending value', $v, $s['value']);
    check('pending value = 4,63,650 (seed)', 46365000, $s['value']);
    check('pending orders', $o, $s['orders']);
    check('customers', $c, $s['customers']);
    check('closed order excluded', 0, (int) Database::value("SELECT COUNT(*) FROM v_pending_order_lines WHERE order_no = 'SO/26-27/0030'"));
    check('previous-FY order still pending (position, not flow)', true, (int) Database::value("SELECT COUNT(*) FROM v_pending_order_lines WHERE order_no = 'SO/25-26/0412'") === 1);
    check('oldest order', 'SO/25-26/0412', $s['oldest']['order_no']);
    check('oldest age in days (16-03-2026 -> 06-10-2026)', 204, $s['oldest']['age']);
    check('current month pending (ordered in October)', rawPending('2026-10-06', "AND o.order_date >= '2026-10-01'")[0], $s['current_value']);
    check('current month orders', 2, $s['current_orders']);

    // Aging buckets independently.
    $rawBucket = static function (int $min, ?int $max) {
        $sql = "AND DATEDIFF('2026-10-06', o.order_date) >= {$min}" . ($max !== null ? " AND DATEDIFF('2026-10-06', o.order_date) <= {$max}" : '');
        return rawPending('2026-10-06', $sql)[0];
    };
    $aging = array_column($s['aging'], 'value', 'key');
    check('bucket 0-30', $rawBucket(0, 30), $aging['0-30']);
    check('bucket 31-60', $rawBucket(31, 60), $aging['31-60']);
    check('bucket 61-90', $rawBucket(61, 90), $aging['61-90']);
    check('bucket 91-150', $rawBucket(91, 150), $aging['91-150']);
    check('bucket 150+', $rawBucket(151, null), $aging['150+']);
    check('buckets sum to total', $s['value'], array_sum($aging));
    check('every bucket has data in the seed', 5, count(array_filter($aging)));

    // Drill-downs reconcile.
    $k = $kpi($admin);
    check('all lines = total', $s['value'], $k->lines(null, 1000, 0)['total']);
    foreach ($s['aging'] as $b) {
        check("lines in {$b['key']} = bucket", $b['value'], $k->lines($b['key'], 1000, 0)['total']);
    }
    check('branch breakdown sums to total', $s['value'], array_sum(array_column($k->breakdown('branch'), 'value')));
    check('employee breakdown sums to total', $s['value'], array_sum(array_column($k->breakdown('employee'), 'value')));

    // Scope / filters / past date.
    $j = $kpi($jana)->summary();
    check('JANA pending = her orders', rawPending('2026-10-06', 'AND o.employee_id = 2')[0], $j['value']);
    $pr = $kpi($admin, ['product' => '1'])->summary();
    check('product filter (corrugated boxes)', rawPending('2026-10-06', 'AND i.product_id = 1')[0], $pr['value']);
    $b2 = $kpi($admin, ['branch' => '2'])->summary();
    check('branch filter', rawPending('2026-10-06', 'AND o.branch_id = 2')[0], $b2['value']);
    $aug = $kpi($admin, ['month' => '2026-08'])->summary();
    check('as on 31-08: only orders dated by then', rawPending('2026-08-31')[0], $aug['value']);
    check('as on 31-08: oldest age measured from 31-08', 168, $aug['oldest']['age']);

    // Live: supplying goods reduces pending; full supply removes the order.
    Database::query("UPDATE pending_order_items i JOIN pending_orders o ON o.id = i.order_id SET i.supplied_value = 26000, i.supplied_qty = 1000 WHERE o.order_no = 'SO/26-27/0048'");
    check('partial supply reduces pending by 26,000', $s['value'] - 2600000, $kpi($admin)->summary()['value']);
    Database::query("UPDATE pending_orders SET status = 'closed' WHERE order_no = 'SO/26-27/0048'");
    check('closed order drops out', $s['value'] - 12600000, $kpi($admin)->summary()['value']);
} finally {
    $pdo->rollBack();
}

exit(test_summary());
