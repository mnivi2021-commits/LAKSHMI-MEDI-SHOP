<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;

/**
 * Step A3 - BRANCH PENDING ORDER.
 *
 * Source: v_pending_order_lines (orders open / partial, not deleted, pending value > 0;
 * pending value is a generated column = order value - supplied value).
 * Pending orders are a position, not a flow: figures show what is pending NOW for
 * orders dated up to the as-on date. Age = as-on date - order date.
 */
final class PendingOrderKpi
{
    public function __construct(private readonly DashboardContext $ctx) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        [$where, $params] = $this->where();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $monthStart = $this->ctx->asOn->modify('first day of this month')->format('Y-m-d');

        $row = Database::fetch(
            "SELECT COALESCE(SUM(p.pending_value), 0) AS value,
                    COUNT(DISTINCT p.order_id) AS orders,
                    COUNT(DISTINCT p.customer_id) AS customers,
                    COALESCE(SUM(CASE WHEN p.order_date >= ? THEN p.pending_value END), 0) AS current_value,
                    COUNT(DISTINCT CASE WHEN p.order_date >= ? THEN p.order_id END) AS current_orders,
                    MIN(p.order_date) AS oldest_date
             FROM v_pending_order_lines p WHERE p.order_date <= ? AND {$where}",
            array_merge([$monthStart, $monthStart, $asOn], $params)
        );

        $oldest = null;
        if ($row['oldest_date'] !== null) {
            $oldest = Database::fetch(
                "SELECT p.order_id, p.order_no, p.order_date, DATEDIFF(?, p.order_date) AS age, SUM(p.pending_value) AS value, c.name AS customer
                 FROM v_pending_order_lines p JOIN customers c ON c.id = p.customer_id
                 WHERE p.order_date = ? AND {$where}
                 GROUP BY p.order_id, p.order_no, p.order_date, c.name ORDER BY p.order_id LIMIT 1",
                array_merge([$asOn, $row['oldest_date']], $params)
            );
            $oldest['value'] = Money::fromDb($oldest['value']);
            $oldest['age'] = (int) $oldest['age'];
        }

        return [
            'as_on'          => $asOn,
            'month_name'     => $this->ctx->asOn->format('F'),
            'value'          => Money::fromDb($row['value']),
            'orders'         => (int) $row['orders'],
            'customers'      => (int) $row['customers'],
            'current_value'  => Money::fromDb($row['current_value']),
            'current_orders' => (int) $row['current_orders'],
            'oldest'         => $oldest,
            'aging'          => $this->aging(),
        ];
    }

    /** @return list<array{key: string, label: string, value: int, orders: int, customers: int}> */
    public function aging(): array
    {
        [$where, $params] = $this->where();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $case = Aging::caseSql('DATEDIFF(?, p.order_date)');
        $rows = Database::query(
            "SELECT {$case} AS bucket, SUM(p.pending_value) AS value, COUNT(DISTINCT p.order_id) AS orders, COUNT(DISTINCT p.customer_id) AS customers
             FROM v_pending_order_lines p WHERE p.order_date <= ? AND {$where} GROUP BY bucket",
            array_merge(array_fill(0, substr_count($case, '?'), $asOn), [$asOn], $params)
        )->fetchAll(\PDO::FETCH_UNIQUE);

        return array_map(static fn (array $b): array => [
            'key'       => $b['key'],
            'label'     => $b['label'],
            'value'     => Money::fromDb($rows[$b['key']]['value'] ?? 0),
            'orders'    => (int) ($rows[$b['key']]['orders'] ?? 0),
            'customers' => (int) ($rows[$b['key']]['customers'] ?? 0),
        ], Aging::buckets());
    }

    /** @return list<array<string, mixed>> */
    public function breakdown(string $by): array
    {
        [$where, $params] = $this->where();
        [$dim, $join, $label] = $by === 'branch'
            ? ['p.branch_id', 'JOIN branches d ON d.id = p.branch_id', "CONCAT(d.name, ' (', d.branch_code, ')')"]
            : ['p.employee_id', 'LEFT JOIN employees d ON d.id = p.employee_id', "COALESCE(CONCAT(d.short_name, ' - ', d.name), 'Not assigned')"];
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $rows = Database::fetchAll(
            "SELECT {$dim} AS id, {$label} AS label, SUM(p.pending_value) AS value, COUNT(DISTINCT p.order_id) AS orders,
                    COUNT(DISTINCT p.customer_id) AS customers, MIN(p.order_date) AS oldest,
                    SUM(CASE WHEN DATEDIFF(?, p.order_date) > 90 THEN p.pending_value ELSE 0 END) AS over_90
             FROM v_pending_order_lines p {$join}
             WHERE p.order_date <= ? AND {$where}
             GROUP BY {$dim}, label ORDER BY value DESC",
            array_merge([$asOn, $asOn], $params)
        );
        return array_map(static fn (array $r): array => [
            'id' => $r['id'] !== null ? (int) $r['id'] : null, 'label' => $r['label'], 'value' => Money::fromDb($r['value']),
            'orders' => (int) $r['orders'], 'customers' => (int) $r['customers'], 'oldest' => $r['oldest'], 'over_90' => Money::fromDb($r['over_90']),
        ], $rows);
    }

    /**
     * Order lines, oldest first, optionally limited to one aging bucket.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, count: int, orders: int}
     */
    public function lines(?string $bucket, int $limit, int $offset): array
    {
        [$where, $params] = $this->where();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $bucketSql = '';
        $bucketParams = [];
        if (($b = Aging::find($bucket)) !== null) {
            $bucketSql = ' AND DATEDIFF(?, p.order_date) >= ?' . ($b['max'] !== null ? ' AND DATEDIFF(?, p.order_date) <= ?' : '');
            $bucketParams = $b['max'] !== null ? [$asOn, $b['min'], $asOn, $b['max']] : [$asOn, $b['min']];
        }
        $bind = array_merge([$asOn], $params, $bucketParams);
        $agg = Database::fetch(
            "SELECT COUNT(*) AS n, COUNT(DISTINCT p.order_id) AS orders, COALESCE(SUM(p.pending_value), 0) AS t
             FROM v_pending_order_lines p WHERE p.order_date <= ? AND {$where}{$bucketSql}",
            $bind
        );
        $rows = Database::fetchAll(
            "SELECT p.*, DATEDIFF(?, p.order_date) AS age, c.name AS customer, c.customer_code, b.branch_code,
                    e.short_name AS employee, pr.name AS product, pr.unit
             FROM v_pending_order_lines p
             JOIN customers c ON c.id = p.customer_id
             JOIN branches b ON b.id = p.branch_id
             LEFT JOIN employees e ON e.id = p.employee_id
             JOIN products pr ON pr.id = p.product_id
             WHERE p.order_date <= ? AND {$where}{$bucketSql}
             ORDER BY p.order_date, p.order_no, p.item_id
             LIMIT {$limit} OFFSET {$offset}",
            array_merge([$asOn], $bind)
        );
        return ['rows' => $rows, 'total' => Money::fromDb($agg['t']), 'count' => (int) $agg['n'], 'orders' => (int) $agg['orders']];
    }

    /** @return array{0: string, 1: list<int>} */
    private function where(): array
    {
        return $this->ctx->where(['branch' => 'p.branch_id', 'employee' => 'p.employee_id', 'customer' => 'p.customer_id', 'product' => 'p.product_id']);
    }
}
