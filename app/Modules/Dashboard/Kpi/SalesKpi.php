<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Database;
use App\Core\DateRange;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Dashboard\DashboardContext;
use DateTimeImmutable;

/**
 * Step A1 - SALES PERFORMANCE. Definitions are in docs/DATABASE.md section 4.
 *
 * Source: v_sales_documents (or v_sales_lines when a product filter is set),
 * so cancelled / deleted invoices never count and credit notes are negative.
 * All money is integer paise. Windows never overlap:
 *   total = (FY start .. previous day) + (as-on day)
 */
final class SalesKpi
{
    private string $col;
    private bool $byLine;

    public function __construct(private readonly DashboardContext $ctx)
    {
        $this->col = Settings::salesBasis() === 'total' ? 'total_value' : 'taxable_value';
        $this->byLine = $ctx->filters['product_id'] !== null;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $w = $this->ctx->windows();
        $fy = $this->ctx->fy;
        $asOn = $this->ctx->asOn;
        $lastMonthEnd = $asOn->modify('first day of this month')->modify('-1 day');

        [$where, $params] = $this->where('v');
        [$p1, $p2] = self::bounds($w['fy_to_previous_day']);
        [$m1, $m2] = self::bounds($w['month_to_previous_day']);
        [$c1, $c2] = self::bounds(DateRange::orNull($fy->start, $lastMonthEnd));

        $row = Database::fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN v.invoice_date BETWEEN ? AND ? THEN v.{$this->col} END), 0) AS fy_prev,
                COALESCE(SUM(CASE WHEN v.invoice_date BETWEEN ? AND ? THEN v.{$this->col} END), 0) AS month_prev,
                COALESCE(SUM(CASE WHEN v.invoice_date = ? THEN v.{$this->col} END), 0) AS today,
                COALESCE(SUM(CASE WHEN v.invoice_date BETWEEN ? AND ? THEN v.{$this->col} END), 0) AS completed,
                COUNT(DISTINCT CASE WHEN v.invoice_date = ? THEN v.invoice_id END) AS today_docs,
                COUNT(DISTINCT v.invoice_id) AS fy_docs,
                COUNT(DISTINCT v.customer_id) AS fy_customers
             FROM {$this->source()} v
             WHERE v.invoice_date BETWEEN ? AND ? AND {$where}",
            array_merge(
                [$p1, $p2, $m1, $m2, $asOn->format('Y-m-d'), $c1, $c2, $asOn->format('Y-m-d'), $fy->start->format('Y-m-d'), $asOn->format('Y-m-d')],
                $params
            )
        );

        $fyPrev = Money::fromDb($row['fy_prev']);
        $monthPrev = Money::fromDb($row['month_prev']);
        $today = Money::fromDb($row['today']);
        $completed = Money::fromDb($row['completed']);
        $total = $fyPrev + $today;

        $target = $this->annualTarget();
        $completedMonths = $fy->completedMonths();
        $remainingMonths = max(1, $fy->remainingMonths());

        $pending = $target === null ? null : max(0, $target - $total);

        return [
            'basis'             => Settings::salesBasis(),
            'as_on'             => $asOn->format('Y-m-d'),
            'is_live'           => $this->ctx->isLive,
            'fy_label'          => $this->ctx->fyRow['label'],
            'month_name'        => $asOn->format('F'),
            'periods'           => [
                'fy_to_previous_day'    => $w['fy_to_previous_day']?->label(),
                'month_to_previous_day' => $w['month_to_previous_day']?->label(),
                'today'                 => $w['today']->label(),
            ],
            'annual_target'     => $target,
            'target_note'       => $target === null ? 'Targets are set per sales employee, so they do not apply to a customer or product filter.' : null,
            'sales_fy_to_previous_day'    => $fyPrev,
            'sales_month_to_previous_day' => $monthPrev,
            'sales_today'       => $today,
            'sales_total'       => $total,
            'achieved_pct'      => $target ? round($total * 100 / $target, 1) : null,
            'target_pending'    => $pending,
            'target_exceeded_by'=> $target !== null && $total > $target ? $total - $target : 0,
            'average_monthly'   => $completedMonths > 0 ? intdiv($completed + intdiv($completedMonths, 2), $completedMonths) : null,
            'completed_months'  => $completedMonths,
            'required_monthly'  => $pending === null ? null : intdiv($pending + intdiv($remainingMonths, 2), $remainingMonths),
            'remaining_months'  => $remainingMonths,
            'documents_today'   => (int) $row['today_docs'],
            'documents_fy'      => (int) $row['fy_docs'],
            'customers_fy'      => (int) $row['fy_customers'],
        ];
    }

    /**
     * Month-by-month target vs sales for the whole FY (future months have sales = null).
     *
     * @return list<array{month: string, label: string, target: ?int, sales: ?int}>
     */
    public function monthly(): array
    {
        $fy = $this->ctx->fy;
        $asOn = $this->ctx->asOn;
        [$where, $params] = $this->where('v');

        $sales = Database::query(
            "SELECT DATE_FORMAT(v.invoice_date, '%Y-%m') AS m, SUM(v.{$this->col}) AS amt
             FROM {$this->source()} v
             WHERE v.invoice_date BETWEEN ? AND ? AND {$where}
             GROUP BY m",
            array_merge([$fy->start->format('Y-m-d'), $asOn->format('Y-m-d')], $params)
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $targets = [];
        if ($this->targetsApply()) {
            [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
            $targets = Database::query(
                "SELECT DATE_FORMAT(t.target_month, '%Y-%m') AS m, SUM(t.sales_target) AS amt
                 FROM sales_targets t WHERE t.financial_year_id = ? AND {$tw} GROUP BY m",
                array_merge([$this->ctx->fyRow['id']], $tp)
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $fy->start->modify("+{$i} month");
            $key = $d->format('Y-m');
            $future = $d > $asOn;
            $out[] = [
                'month'  => $key,
                'label'  => $d->format('M'),
                'target' => $this->targetsApply() ? Money::fromDb($targets[$key] ?? 0) : null,
                'sales'  => $future ? null : Money::fromDb($sales[$key] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * FY-to-date / month-to-date / as-on-day sales and target grouped by branch or employee.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(string $by): array
    {
        $fy = $this->ctx->fy;
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $monthStart = $this->ctx->asOn->modify('first day of this month')->format('Y-m-d');
        [$where, $params] = $this->where('v');

        if ($by === 'branch') {
            $dim = 'v.branch_id';
            $join = 'JOIN branches d ON d.id = v.branch_id';
            $label = "CONCAT(d.name, ' (', d.branch_code, ')')";
        } else {
            $dim = 'v.employee_id';
            $join = 'LEFT JOIN employees d ON d.id = v.employee_id';
            $label = "COALESCE(CONCAT(d.short_name, ' - ', d.name), 'Not assigned')";
        }

        $rows = Database::fetchAll(
            "SELECT {$dim} AS id, {$label} AS label,
                    SUM(v.{$this->col}) AS fy_to_date,
                    COALESCE(SUM(CASE WHEN v.invoice_date >= ? THEN v.{$this->col} END), 0) AS month_to_date,
                    COALESCE(SUM(CASE WHEN v.invoice_date = ? THEN v.{$this->col} END), 0) AS on_day
             FROM {$this->source()} v {$join}
             WHERE v.invoice_date BETWEEN ? AND ? AND {$where}
             GROUP BY {$dim}, label",
            array_merge([$monthStart, $asOn, $fy->start->format('Y-m-d'), $asOn], $params)
        );

        $targets = [];
        if ($this->targetsApply()) {
            $tdim = $by === 'branch' ? 't.branch_id' : 't.employee_id';
            [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
            $targets = Database::query(
                "SELECT {$tdim}, SUM(t.sales_target) FROM sales_targets t WHERE t.financial_year_id = ? AND {$tw} GROUP BY {$tdim}",
                array_merge([$this->ctx->fyRow['id']], $tp)
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        $out = [];
        foreach ($rows as $r) {
            $sales = Money::fromDb($r['fy_to_date']);
            $target = $this->targetsApply() ? Money::fromDb($targets[$r['id']] ?? 0) : null;
            $out[] = [
                'id'            => $r['id'] !== null ? (int) $r['id'] : null,
                'label'         => $r['label'],
                'fy_to_date'    => $sales,
                'month_to_date' => Money::fromDb($r['month_to_date']),
                'on_day'        => Money::fromDb($r['on_day']),
                'target'        => $target,
                'achieved_pct'  => $target ? round($sales * 100 / $target, 1) : null,
            ];
        }
        // Targets for people/branches with no sales yet still matter.
        foreach ($targets as $id => $t) {
            if (!in_array((int) $id, array_column($out, 'id'), true)) {
                $name = $by === 'branch'
                    ? Database::value("SELECT CONCAT(name, ' (', branch_code, ')') FROM branches WHERE id = ?", [$id])
                    : Database::value("SELECT CONCAT(short_name, ' - ', name) FROM employees WHERE id = ?", [$id]);
                $out[] = ['id' => (int) $id, 'label' => $name, 'fy_to_date' => 0, 'month_to_date' => 0, 'on_day' => 0,
                          'target' => Money::fromDb($t), 'achieved_pct' => 0.0];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['fy_to_date'] <=> $a['fy_to_date']);
        return $out;
    }

    /**
     * Documents behind one window, for the drill-down list and CSV export.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, count: int}
     */
    public function documents(DateRange $range, int $limit, int $offset): array
    {
        [$where, $params] = $this->where('v');
        $base = "FROM {$this->source()} v WHERE v.invoice_date BETWEEN ? AND ? AND {$where}";
        $bind = array_merge([$range->from(), $range->to()], $params);

        $agg = Database::fetch("SELECT COUNT(DISTINCT v.invoice_id) AS n, COALESCE(SUM(v.{$this->col}), 0) AS total {$base}", $bind);

        $rows = Database::fetchAll(
            "SELECT v.invoice_id, v.invoice_no, v.invoice_date, v.document_type,
                    SUM(v.taxable_value) AS taxable_value, SUM(v.total_value) AS total_value,
                    c.name AS customer, c.customer_code, b.branch_code, e.short_name AS employee
             FROM {$this->source()} v
             JOIN customers c ON c.id = v.customer_id
             JOIN branches b ON b.id = v.branch_id
             LEFT JOIN employees e ON e.id = v.employee_id
             WHERE v.invoice_date BETWEEN ? AND ? AND {$where}
             GROUP BY v.invoice_id, v.invoice_no, v.invoice_date, v.document_type, c.name, c.customer_code, b.branch_code, e.short_name
             ORDER BY v.invoice_date DESC, v.invoice_id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $bind
        );

        return ['rows' => $rows, 'total' => Money::fromDb($agg['total']), 'count' => (int) $agg['n']];
    }

    public function basisColumn(): string
    {
        return $this->col;
    }

    public function targetsApply(): bool
    {
        return $this->ctx->filters['customer_id'] === null && $this->ctx->filters['product_id'] === null;
    }

    // -------------------------------------------------------------------------

    private function annualTarget(): ?int
    {
        if (!$this->targetsApply()) {
            return null;
        }
        [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
        return Money::fromDb(Database::value(
            "SELECT COALESCE(SUM(t.sales_target), 0) FROM sales_targets t WHERE t.financial_year_id = ? AND {$tw}",
            array_merge([$this->ctx->fyRow['id']], $tp)
        ));
    }

    private function source(): string
    {
        return $this->byLine ? 'v_sales_lines' : 'v_sales_documents';
    }

    /** @return array{0: string, 1: list<int>} */
    private function where(string $alias): array
    {
        $cols = ['branch' => "{$alias}.branch_id", 'employee' => "{$alias}.employee_id", 'customer' => "{$alias}.customer_id"];
        if ($this->byLine) {
            $cols['product'] = "{$alias}.product_id";
        }
        return $this->ctx->where($cols);
    }

    /** Empty windows become an impossible range so the CASE never matches. @return array{0: string, 1: string} */
    private static function bounds(?DateRange $r): array
    {
        return $r ? [$r->from(), $r->to()] : ['9999-12-31', '0001-01-01'];
    }
}
