<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;

/**
 * Outstanding by age - the "90 DAYS" and "150 DAYS" boxes and the
 * representative panel's overdue columns. Bills come from OpenBills
 * (computed or imported); age = as-on date - invoice date (or due date,
 * settings outstanding.aging_basis).
 *
 *   upto90   0-90 days      (current / not yet a problem)
 *   d90      91-150 days    -> "90 DAYS" box
 *   d150     over 150 days  -> "150 DAYS" box
 */
final class OutstandingKpi
{
    public const CATEGORIES = [
        'upto90' => ['label' => 'Up to 90 days', 'short' => '0-90', 'min' => 0, 'max' => 90],
        'd90'    => ['label' => '90 days (91-150)', 'short' => '91-150', 'min' => 91, 'max' => 150],
        'd150'   => ['label' => '150 days (over 150)', 'short' => '150+', 'min' => 151, 'max' => null],
    ];

    public function __construct(private readonly DashboardContext $ctx) {}

    public function applies(): bool
    {
        return $this->ctx->filters['product_id'] === null;
    }

    /** @return array<string, array{label: string, short: string, value: int, customers: int, bills: int}> */
    public function categories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => $c) {
            $out[$key] = ['label' => $c['label'], 'short' => $c['short'], 'value' => 0, 'customers' => 0, 'bills' => 0];
        }
        if (!$this->applies()) {
            return $out;
        }

        [$where, $params] = $this->where();
        $age = $this->ageExpr();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $rows = Database::fetchAll(
            "SELECT CASE WHEN {$age} <= 90 THEN 'upto90' WHEN {$age} <= 150 THEN 'd90' ELSE 'd150' END AS cat,
                    SUM(ob.balance) AS value, COUNT(DISTINCT ob.customer_id) AS customers, COUNT(*) AS bills
             FROM " . OpenBills::sql() . " ob WHERE ob.invoice_date <= ? AND {$where} GROUP BY cat",
            array_merge([$asOn, $asOn, $asOn], $params)
        );
        foreach ($rows as $r) {
            $out[$r['cat']]['value'] = Money::fromDb($r['value']);
            $out[$r['cat']]['customers'] = (int) $r['customers'];
            $out[$r['cat']]['bills'] = (int) $r['bills'];
        }
        return $out;
    }

    public function total(): int
    {
        return array_sum(array_column($this->categories(), 'value'));
    }

    /**
     * Bills in one category (or all), oldest first.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, count: int, customers: int}
     */
    public function bills(?string $category, int $limit, int $offset): array
    {
        if (!$this->applies()) {
            return ['rows' => [], 'total' => 0, 'count' => 0, 'customers' => 0];
        }
        [$where, $params] = $this->where();
        [$catSql, $catParams] = $this->categorySql($category);
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $bind = array_merge([$asOn], $params, $catParams);
        $from = 'FROM ' . OpenBills::sql() . " ob WHERE ob.invoice_date <= ? AND {$where}{$catSql}";
        $agg = Database::fetch("SELECT COUNT(*) AS n, COUNT(DISTINCT ob.customer_id) AS c, COALESCE(SUM(ob.balance), 0) AS t {$from}", $bind);
        $age = $this->ageExpr();
        $rows = Database::fetchAll(
            "SELECT ob.*, {$age} AS age, DATEDIFF(?, ob.due_date) AS days_past_due,
                    cu.name AS customer, cu.customer_code, cu.mobile AS customer_mobile, cu.city,
                    b.branch_code, b.name AS branch, e.short_name AS employee, e.name AS employee_name
             FROM " . OpenBills::sql() . " ob
             JOIN customers cu ON cu.id = ob.customer_id
             JOIN branches b ON b.id = ob.branch_id
             LEFT JOIN employees e ON e.id = ob.employee_id
             WHERE ob.invoice_date <= ? AND {$where}{$catSql}
             ORDER BY ob.invoice_date, ob.invoice_no LIMIT {$limit} OFFSET {$offset}",
            array_merge([$asOn, $asOn], $bind)
        );
        return ['rows' => $rows, 'total' => Money::fromDb($agg['t']), 'count' => (int) $agg['n'], 'customers' => (int) $agg['c']];
    }

    /**
     * Customer-wise totals for one category.
     *
     * @return list<array<string, mixed>>
     */
    public function customers(?string $category): array
    {
        if (!$this->applies()) {
            return [];
        }
        [$where, $params] = $this->where();
        [$catSql, $catParams] = $this->categorySql($category);
        $age = $this->ageExpr();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $rows = Database::fetchAll(
            "SELECT ob.customer_id, cu.name AS customer, cu.customer_code, cu.mobile, b.branch_code, e.short_name AS employee,
                    COUNT(*) AS bills, SUM(ob.balance) AS value, MAX({$age}) AS oldest_age, MIN(ob.due_date) AS earliest_due
             FROM " . OpenBills::sql() . " ob
             JOIN customers cu ON cu.id = ob.customer_id
             JOIN branches b ON b.id = cu.branch_id
             LEFT JOIN employees e ON e.id = cu.employee_id
             WHERE ob.invoice_date <= ? AND {$where}{$catSql}
             GROUP BY ob.customer_id, cu.name, cu.customer_code, cu.mobile, b.branch_code, e.short_name
             ORDER BY value DESC",
            array_merge([$asOn, $asOn], $params, $catParams)
        );
        foreach ($rows as &$r) {
            $r['value'] = Money::fromDb($r['value']);
        }
        return $rows;
    }

    // -------------------------------------------------------------------------

    /** @return array{0: string, 1: list<string|int>} */
    private function categorySql(?string $category): array
    {
        if ($category === null || !isset(self::CATEGORIES[$category])) {
            return ['', []];
        }
        $c = self::CATEGORIES[$category];
        $age = $this->ageExpr();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        return $c['max'] === null
            ? [" AND {$age} >= ?", [$asOn, $c['min']]]
            : [" AND {$age} BETWEEN ? AND ?", [$asOn, $c['min'], $c['max']]];
    }

    /** Age expression with ONE ? placeholder (the as-on date). */
    private function ageExpr(): string
    {
        return 'DATEDIFF(?, ' . OpenBills::ageColumn('ob') . ')';
    }

    /** @return array{0: string, 1: list<int>} */
    private function where(): array
    {
        return $this->ctx->where(['branch' => 'ob.branch_id', 'employee' => 'ob.employee_id', 'customer' => 'ob.customer_id']);
    }
}
