<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Database;
use App\Core\DateRange;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;

/**
 * Step A2 - PAYMENT COLLECTION.
 *
 * Source: v_valid_collections (status received / cleared, not deleted; bounced and
 * cancelled cheques never count). Windows never overlap:
 *   previous period = 1st of month .. previous day
 *   today           = as-on day
 *   total           = previous period + today  (= month to date)
 * Overdue = open bills (OpenBills) whose due date is before the as-on date.
 * Collections are not per product: with a product filter, figures are not applicable.
 */
final class CollectionKpi
{
    public function __construct(private readonly DashboardContext $ctx) {}

    public function applies(): bool
    {
        return $this->ctx->filters['product_id'] === null;
    }

    public function targetsApply(): bool
    {
        return $this->applies() && $this->ctx->filters['customer_id'] === null;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $w = $this->ctx->windows();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $base = [
            'applies'      => $this->applies(),
            'not_applicable_note' => $this->applies() ? null : 'Payments are received per customer, not per product, so collection figures do not apply to a product filter.',
            'as_on'        => $asOn,
            'is_live'      => $this->ctx->isLive,
            'month_name'   => $this->ctx->asOn->format('F'),
            'fy_label'     => $this->ctx->fyRow['label'],
            'periods'      => [
                'month_to_previous_day' => $w['month_to_previous_day']?->label(),
                'today'                 => $w['today']->label(),
                'month_to_date'         => $w['month_to_date']->label(),
                'fy_to_date'            => $w['fy_to_date']->label(),
            ],
        ];
        if (!$this->applies()) {
            return $base + array_fill_keys(['previous_period', 'today', 'total', 'fy_to_date', 'collection_target', 'collection_pct',
                'collection_pending', 'customers', 'payments', 'payments_today', 'overdue', 'overdue_customers', 'overdue_bills',
                'outstanding', 'unallocated'], null) + ['target_note' => null];
        }

        [$where, $params] = $this->where('c');
        [$m1, $m2] = self::bounds($w['month_to_previous_day']);
        $monthStart = $this->ctx->asOn->modify('first day of this month')->format('Y-m-d');

        $row = Database::fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN c.receipt_date BETWEEN ? AND ? THEN c.amount END), 0) AS prev,
                COALESCE(SUM(CASE WHEN c.receipt_date = ? THEN c.amount END), 0) AS today,
                COALESCE(SUM(c.amount), 0) AS fy,
                COUNT(DISTINCT CASE WHEN c.receipt_date >= ? THEN c.customer_id END) AS customers,
                COUNT(CASE WHEN c.receipt_date >= ? THEN 1 END) AS payments,
                COUNT(CASE WHEN c.receipt_date = ? THEN 1 END) AS payments_today,
                COALESCE(SUM(CASE WHEN c.receipt_date >= ? THEN c.unallocated_amount END), 0) AS unallocated
             FROM v_valid_collections c
             WHERE c.receipt_date BETWEEN ? AND ? AND {$where}",
            array_merge([$m1, $m2, $asOn, $monthStart, $monthStart, $asOn, $monthStart, $this->ctx->fy->start->format('Y-m-d'), $asOn], $params)
        );

        $prev = Money::fromDb($row['prev']);
        $today = Money::fromDb($row['today']);
        $total = $prev + $today;
        $target = $this->monthTarget();
        $overdue = $this->overdueSummary();

        return $base + [
            'previous_period'    => $prev,
            'today'              => $today,
            'total'              => $total,
            'fy_to_date'         => Money::fromDb($row['fy']),
            'collection_target'  => $target,
            'target_note'        => $target === null ? 'Collection targets are set per sales employee, so they do not apply to a customer filter.' : null,
            'collection_pct'     => $target ? round($total * 100 / $target, 1) : null,
            'collection_pending' => $target === null ? null : max(0, $target - $total),
            'customers'          => (int) $row['customers'],
            'payments'           => (int) $row['payments'],
            'payments_today'     => (int) $row['payments_today'],
            'unallocated'        => Money::fromDb($row['unallocated']),
            'overdue'            => $overdue['overdue'],
            'overdue_customers'  => $overdue['customers'],
            'overdue_bills'      => $overdue['bills'],
            'outstanding'        => $overdue['outstanding'],
        ];
    }

    /**
     * Month-wise collection against collection target (for the chart / table).
     *
     * @return list<array{month: string, label: string, target: ?int, sales: ?int}>
     */
    public function monthly(): array
    {
        $fy = $this->ctx->fy;
        [$where, $params] = $this->where('c');
        $amounts = Database::query(
            "SELECT DATE_FORMAT(c.receipt_date, '%Y-%m') AS m, SUM(c.amount) FROM v_valid_collections c
             WHERE c.receipt_date BETWEEN ? AND ? AND {$where} GROUP BY m",
            array_merge([$fy->start->format('Y-m-d'), $this->ctx->asOn->format('Y-m-d')], $params)
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $targets = [];
        if ($this->targetsApply()) {
            [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
            $targets = Database::query(
                "SELECT DATE_FORMAT(t.target_month, '%Y-%m') AS m, SUM(t.collection_target) FROM sales_targets t
                 WHERE t.financial_year_id = ? AND {$tw} GROUP BY m",
                array_merge([$this->ctx->fyRow['id']], $tp)
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $fy->start->modify("+{$i} month");
            $k = $d->format('Y-m');
            $out[] = [
                'month'  => $k,
                'label'  => $d->format('M'),
                'target' => $this->targetsApply() ? Money::fromDb($targets[$k] ?? 0) : null,
                'sales'  => $d > $this->ctx->asOn ? null : Money::fromDb($amounts[$k] ?? 0),   // 'sales' = bar value
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function breakdown(string $by): array
    {
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $monthStart = $this->ctx->asOn->modify('first day of this month')->format('Y-m-d');
        [$where, $params] = $this->where('c');
        [$dim, $join, $label] = $by === 'branch'
            ? ['c.branch_id', 'JOIN branches d ON d.id = c.branch_id', "CONCAT(d.name, ' (', d.branch_code, ')')"]
            : ['c.employee_id', 'LEFT JOIN employees d ON d.id = c.employee_id', "COALESCE(CONCAT(d.short_name, ' - ', d.name), 'Not assigned')"];

        $rows = Database::fetchAll(
            "SELECT {$dim} AS id, {$label} AS label,
                    COALESCE(SUM(CASE WHEN c.receipt_date >= ? THEN c.amount END), 0) AS month_to_date,
                    COALESCE(SUM(CASE WHEN c.receipt_date = ? THEN c.amount END), 0) AS on_day,
                    SUM(c.amount) AS fy_to_date
             FROM v_valid_collections c {$join}
             WHERE c.receipt_date BETWEEN ? AND ? AND {$where}
             GROUP BY {$dim}, label",
            array_merge([$monthStart, $asOn, $this->ctx->fy->start->format('Y-m-d'), $asOn], $params)
        );

        $targets = [];
        if ($this->targetsApply()) {
            $tdim = $by === 'branch' ? 't.branch_id' : 't.employee_id';
            [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
            $targets = Database::query(
                "SELECT {$tdim}, SUM(t.collection_target) FROM sales_targets t WHERE t.target_month = ? AND {$tw} GROUP BY {$tdim}",
                array_merge([$monthStart], $tp)
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        $out = [];
        foreach ($rows as $r) {
            $mtd = Money::fromDb($r['month_to_date']);
            $t = $this->targetsApply() ? Money::fromDb($targets[$r['id']] ?? 0) : null;
            $out[] = ['id' => $r['id'] !== null ? (int) $r['id'] : null, 'label' => $r['label'], 'month_to_date' => $mtd,
                      'on_day' => Money::fromDb($r['on_day']), 'fy_to_date' => Money::fromDb($r['fy_to_date']),
                      'target' => $t, 'pct' => $t ? round($mtd * 100 / $t, 1) : null];
        }
        usort($out, static fn ($a, $b) => $b['month_to_date'] <=> $a['month_to_date']);
        return $out;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, count: int} */
    public function receipts(DateRange $range, int $limit, int $offset): array
    {
        [$where, $params] = $this->where('c');
        $bind = array_merge([$range->from(), $range->to()], $params);
        $agg = Database::fetch("SELECT COUNT(*) AS n, COALESCE(SUM(c.amount), 0) AS t FROM v_valid_collections c WHERE c.receipt_date BETWEEN ? AND ? AND {$where}", $bind);
        $rows = Database::fetchAll(
            "SELECT c.collection_id, c.receipt_no, c.receipt_date, c.amount, c.allocated_amount, c.unallocated_amount,
                    c.payment_mode, c.reference_no, c.status, cu.name AS customer, cu.customer_code, b.branch_code, e.short_name AS employee
             FROM v_valid_collections c
             JOIN customers cu ON cu.id = c.customer_id
             JOIN branches b ON b.id = c.branch_id
             LEFT JOIN employees e ON e.id = c.employee_id
             WHERE c.receipt_date BETWEEN ? AND ? AND {$where}
             ORDER BY c.receipt_date DESC, c.collection_id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind
        );
        return ['rows' => $rows, 'total' => Money::fromDb($agg['t']), 'count' => (int) $agg['n']];
    }

    /** Overdue bills, oldest first. @return array{rows: list<array<string, mixed>>, total: int, count: int} */
    public function overdueBills(int $limit, int $offset): array
    {
        [$where, $params] = $this->ctx->where(['branch' => 'ob.branch_id', 'employee' => 'ob.employee_id', 'customer' => 'ob.customer_id']);
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $bind = array_merge([$asOn], $params);
        $from = 'FROM ' . OpenBills::sql() . " ob WHERE ob.due_date < ? AND {$where}";
        $agg = Database::fetch("SELECT COUNT(*) AS n, COALESCE(SUM(ob.balance), 0) AS t {$from}", $bind);
        $rows = Database::fetchAll(
            "SELECT ob.*, DATEDIFF(?, ob.due_date) AS days_overdue, cu.name AS customer, cu.customer_code, b.branch_code, e.short_name AS employee
             FROM " . OpenBills::sql() . " ob
             JOIN customers cu ON cu.id = ob.customer_id
             JOIN branches b ON b.id = ob.branch_id
             LEFT JOIN employees e ON e.id = ob.employee_id
             WHERE ob.due_date < ? AND {$where}
             ORDER BY ob.due_date, ob.invoice_no LIMIT {$limit} OFFSET {$offset}",
            array_merge([$asOn], $bind)
        );
        return ['rows' => $rows, 'total' => Money::fromDb($agg['t']), 'count' => (int) $agg['n']];
    }

    // -------------------------------------------------------------------------

    private function monthTarget(): ?int
    {
        if (!$this->targetsApply()) {
            return null;
        }
        [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
        return Money::fromDb(Database::value(
            "SELECT COALESCE(SUM(t.collection_target), 0) FROM sales_targets t WHERE t.target_month = ? AND {$tw}",
            array_merge([$this->ctx->asOn->modify('first day of this month')->format('Y-m-d')], $tp)
        ));
    }

    /** @return array{overdue: int, customers: int, bills: int, outstanding: int} */
    private function overdueSummary(): array
    {
        [$where, $params] = $this->ctx->where(['branch' => 'ob.branch_id', 'employee' => 'ob.employee_id', 'customer' => 'ob.customer_id']);
        $r = Database::fetch(
            'SELECT COALESCE(SUM(CASE WHEN ob.due_date < ? THEN ob.balance END), 0) AS overdue,
                    COUNT(DISTINCT CASE WHEN ob.due_date < ? THEN ob.customer_id END) AS customers,
                    COUNT(CASE WHEN ob.due_date < ? THEN 1 END) AS bills,
                    COALESCE(SUM(ob.balance), 0) AS outstanding
             FROM ' . OpenBills::sql() . " ob WHERE {$where}",
            array_merge(array_fill(0, 3, $this->ctx->asOn->format('Y-m-d')), $params)
        );
        return ['overdue' => Money::fromDb($r['overdue']), 'customers' => (int) $r['customers'], 'bills' => (int) $r['bills'],
                'outstanding' => Money::fromDb($r['outstanding'])];
    }

    /** @return array{0: string, 1: list<int>} */
    private function where(string $a): array
    {
        return $this->ctx->where(['branch' => "{$a}.branch_id", 'employee' => "{$a}.employee_id", 'customer' => "{$a}.customer_id"]);
    }

    /** @return array{0: string, 1: string} */
    private static function bounds(?DateRange $r): array
    {
        return $r ? [$r->from(), $r->to()] : ['9999-12-31', '0001-01-01'];
    }
}
