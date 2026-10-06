<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Core\Database;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\OpenBills;

/**
 * Phase 13 - Sales Details: one combined grid per employee, branch or month.
 *
 * Every column uses the SAME source and window as its dashboard KPI, so the
 * grid's totals row equals the dashboard (tests/sales_grid.php proves this):
 *   target       sum of sales_targets for the FY            (SalesKpi annual target)
 *   sales        v_sales_documents, FY start .. as-on       (SalesKpi sales_total)
 *   collection   v_valid_collections, FY start .. as-on     (CollectionKpi fy_to_date)
 *   pending      v_pending_order_lines as on                (PendingOrderKpi value)
 *   samples/dc   v_pending_sample_lines / v_pending_dc_lines (SampleDcKpi)
 *   outstanding  OpenBills as on, split 0-90 / 91-150 / 150+ (OutstandingKpi)
 *
 * Grouping by month shows only the flow columns (target, sales, collection);
 * pending / outstanding are point-in-time balances and have no month split.
 */
final class SalesGrid
{
    public const GROUPS = ['employee' => 'Employee', 'branch' => 'Branch', 'month' => 'Month'];
    public const COLUMNS = ['target', 'sales', 'collection', 'pending', 'samples', 'dc', 'outstanding', 'd90', 'd150'];
    private const FLOW = ['target', 'sales', 'collection'];

    private string $salesCol;

    /** @param list<string>|null $allowed columns the user may see (null = all) */
    public function __construct(private readonly DashboardContext $ctx, private readonly ?array $allowed = null)
    {
        $this->salesCol = Settings::salesBasis() === 'total' ? 'total_value' : 'taxable_value';
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, int>, columns: list<string>} */
    public function build(string $by): array
    {
        $by = array_key_exists($by, self::GROUPS) ? $by : 'employee';
        $columns = $by === 'month' ? self::FLOW : self::COLUMNS;
        if ($this->allowed !== null) {
            $columns = array_values(array_intersect($columns, $this->allowed));
        }
        $want = array_fill_keys($columns, true);
        $fyStart = $this->ctx->fy->start->format('Y-m-d');
        $asOn = $this->ctx->asOn->format('Y-m-d');

        $data = [];
        if (isset($want['target'])) {
            $data['target'] = $this->targets($by);
        }
        if (isset($want['sales'])) {
            $data['sales'] = $this->grouped('v_sales_documents', 'v', "v.{$this->salesCol}", 'v.invoice_date', $by,
                "v.invoice_date BETWEEN ? AND ?", [$fyStart, $asOn]);
        }
        if (isset($want['collection'])) {
            $data['collection'] = $this->grouped('v_valid_collections', 'c', 'c.amount', 'c.receipt_date', $by,
                "c.receipt_date BETWEEN ? AND ?", [$fyStart, $asOn]);
        }
        if (isset($want['pending'])) {
            $data['pending'] = $this->grouped('v_pending_order_lines', 'p', 'p.pending_value', 'p.order_date', $by, 'p.order_date <= ?', [$asOn]);
        }
        if (isset($want['samples'])) {
            $data['samples'] = $this->grouped('v_pending_sample_lines', 'x', 'x.sample_value', 'x.document_date', $by, 'x.document_date <= ?', [$asOn]);
        }
        if (isset($want['dc'])) {
            $data['dc'] = $this->grouped('v_pending_dc_lines', 'x', 'x.dc_value', 'x.dc_date', $by, 'x.dc_date <= ?', [$asOn]);
        }
        if (isset($want['outstanding'])) {
            [$data['outstanding'], $data['d90'], $data['d150']] = $this->outstanding($by);
        }

        $keys = [];
        foreach ($data as $values) {
            $keys += array_fill_keys(array_keys($values), true);
        }
        if ($by === 'month') {
            $keys = [];
            for ($i = 0; $i < 12; $i++) {
                $d = $this->ctx->fy->start->modify("+{$i} month");
                if ($d <= $this->ctx->asOn || isset($data['target'][$d->format('Y-m')])) {
                    $keys[$d->format('Y-m')] = true;
                }
            }
        }

        $labels = $this->labels($by, array_keys($keys));
        $rows = [];
        $totals = array_fill_keys($columns, 0);
        foreach (array_keys($keys) as $key) {
            $row = ['key' => $key, 'label' => $labels[$key] ?? (string) $key];
            foreach ($columns as $col) {
                $row[$col] = $data[$col][$key] ?? 0;
                $totals[$col] += $row[$col];
            }
            $row['achieved_pct'] = ($row['target'] ?? 0) > 0 && isset($row['sales']) ? round($row['sales'] * 100 / $row['target'], 1) : null;
            $rows[] = $row;
        }
        if ($by !== 'month') {
            usort($rows, static fn (array $a, array $b): int => array_values(array_intersect_key($b, $want)) <=> array_values(array_intersect_key($a, $want)));
        }
        $totals['achieved_pct'] = ($totals['target'] ?? 0) > 0 && isset($totals['sales']) ? round($totals['sales'] * 100 / $totals['target'], 1) : null;

        return ['rows' => $rows, 'totals' => $totals, 'columns' => $columns];
    }

    // -------------------------------------------------------------------------

    /** Dimension expression for a row alias. Unassigned employee = key 0. */
    private static function dim(string $by, string $alias, string $dateCol): string
    {
        return match ($by) {
            'branch' => "{$alias}.branch_id",
            'month'  => "DATE_FORMAT({$dateCol}, '%Y-%m')",
            default  => "COALESCE({$alias}.employee_id, 0)",
        };
    }

    /** @return array<string|int, int> key => paise */
    private function grouped(string $view, string $a, string $valueExpr, string $dateCol, string $by, string $cond, array $condParams): array
    {
        [$where, $params] = $this->ctx->where(['branch' => "{$a}.branch_id", 'employee' => "{$a}.employee_id"]);
        $dim = self::dim($by, $a, $dateCol);
        $rows = Database::query(
            "SELECT {$dim} AS k, SUM({$valueExpr}) AS v FROM {$view} {$a} WHERE {$cond} AND {$where} GROUP BY k",
            array_merge($condParams, $params)
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map([Money::class, 'fromDb'], $rows);
    }

    /** @return array<string|int, int> */
    private function targets(string $by): array
    {
        [$where, $params] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
        $dim = self::dim($by, 't', 't.target_month');
        $rows = Database::query(
            "SELECT {$dim} AS k, SUM(t.sales_target) AS v FROM sales_targets t WHERE t.financial_year_id = ? AND {$where} GROUP BY k",
            array_merge([$this->ctx->fyRow['id']], $params)
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map([Money::class, 'fromDb'], $rows);
    }

    /** @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>} total, 91-150, 150+ */
    private function outstanding(string $by): array
    {
        [$where, $params] = $this->ctx->where(['branch' => 'ob.branch_id', 'employee' => 'ob.employee_id']);
        $dim = self::dim($by, 'ob', 'ob.invoice_date');
        $age = 'DATEDIFF(?, ' . OpenBills::ageColumn('ob') . ')';
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $rows = Database::fetchAll(
            "SELECT {$dim} AS k, SUM(ob.balance) AS total,
                    SUM(CASE WHEN {$age} BETWEEN 91 AND 150 THEN ob.balance ELSE 0 END) AS d90,
                    SUM(CASE WHEN {$age} > 150 THEN ob.balance ELSE 0 END) AS d150
             FROM " . OpenBills::sql() . " ob WHERE ob.invoice_date <= ? AND {$where} GROUP BY k",
            array_merge([$asOn, $asOn, $asOn], $params)
        );
        $out = [[], [], []];
        foreach ($rows as $r) {
            $out[0][$r['k']] = Money::fromDb($r['total']);
            $out[1][$r['k']] = Money::fromDb($r['d90']);
            $out[2][$r['k']] = Money::fromDb($r['d150']);
        }
        return $out;
    }

    /** @param list<string|int> $keys @return array<string|int, string> */
    private function labels(string $by, array $keys): array
    {
        if ($by === 'month') {
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = date('M Y', strtotime($k . '-01'));
            }
            return $out;
        }
        $ids = array_values(array_filter(array_map('intval', $keys)));
        $out = $by === 'employee' ? [0 => 'Not assigned'] : [];
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = $by === 'branch'
            ? "SELECT id, CONCAT(name, ' (', branch_code, ')') FROM branches WHERE id IN ({$in})"
            : "SELECT id, CONCAT(COALESCE(short_name, employee_code), ' - ', name) FROM employees WHERE id IN ({$in})";
        return $out + Database::query($sql, $ids)->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
