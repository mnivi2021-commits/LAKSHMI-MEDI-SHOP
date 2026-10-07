<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;

/**
 * Branch Performance (dashboard first page), built from the daily entry sheets.
 *
 *   Business done (sales, collection, NOB, NOC, leads created) ADDS UP over a period:
 *     sales as on previous day = FY start .. as-on minus 1 day
 *     this month sales         = 1st of month .. as-on minus 1 day
 *     today                    = the as-on day
 *   Positions (pending orders, enquiries, open DC, samples, overdue, 90 / 150 days) are
 *   NOT added up over days: each rep's latest figure on or before the as-on date is used
 *   (a blank cell means "unchanged"), then reps are added together.
 *
 *   Targets come from sales_targets (annual = FY months, month = that month).
 *   Opening outstanding comes from rep_month_openings for the as-on month.
 *
 * All money is integer paise.
 */
final class BranchPerformance
{
    public const POSITIONS = [
        'po_non_stock', 'po_price_issue', 'po_doubt', 'enq_new_customer', 'enq_new_product',
        'dc_order', 'dc_mail', 'dc_rep_inform', 'sample_returnable', 'sample_non_returnable',
        'os_overdue', 'os_90', 'os_150',
    ];
    public const COUNT_POSITIONS = ['enq_new_customer', 'enq_new_product'];

    private string $fyStart;
    private string $monthStart;
    private string $asOn;
    private string $prevDay;

    public function __construct(private readonly DashboardContext $ctx)
    {
        $this->fyStart = $ctx->fy->start->format('Y-m-d');
        $this->monthStart = $ctx->asOn->modify('first day of this month')->format('Y-m-d');
        $this->asOn = $ctx->asOn->format('Y-m-d');
        $this->prevDay = $ctx->asOn->modify('-1 day')->format('Y-m-d');
    }

    /** @return array<string, string> date labels for column headings */
    public function periods(): array
    {
        $d = static fn (string $s): string => date('d-m-Y', strtotime($s));
        $prevOk = $this->prevDay >= $this->fyStart;
        $monthOk = $this->prevDay >= $this->monthStart;
        return [
            'fy_prev'    => $prevOk ? $d($this->fyStart) . ' to ' . $d($this->prevDay) : 'no days yet',
            'month_prev' => $monthOk ? $d($this->monthStart) . ' to ' . $d($this->prevDay) : 'no days yet',
            'today'      => $d($this->asOn),
            'month'      => date('F Y', strtotime($this->asOn)),
            'as_on'      => $d($this->asOn),
            // Share of the period already completed (up to the previous day), used to colour
            // the % figures: being at 19% on the 7th of a 31-day month is on pace, not behind.
            'month_pace' => round(((int) date('j', strtotime($this->asOn)) - 1) * 100 / (int) date('t', strtotime($this->asOn)), 1),
            'fy_pace'    => round(max(0, (strtotime($this->asOn) - strtotime($this->fyStart)) / 86400) * 100
                              / ((strtotime($this->ctx->fy->end->format('Y-m-d')) - strtotime($this->fyStart)) / 86400 + 1), 1),
        ];
    }

    // =========================================================================
    // 1. Sales performance (rows: branches, or a branch and its reps, or one rep)
    // =========================================================================

    /** @return array{rows: list<array<string, mixed>>, total: array<string, mixed>, by: string} */
    public function sales(): array
    {
        $f = $this->ctx->filters;
        $by = $f['employee_id'] !== null || $f['branch_id'] !== null ? 'employee' : 'branch';
        $dim = $by === 'branch' ? 'branch_id' : 'employee_id';

        [$w, $p] = $this->where('x');
        $flows = Database::fetchAll(
            "SELECT x.{$dim} AS k,
                    COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.sales_value END), 0) AS fy_prev,
                    COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.sales_value END), 0) AS month_prev,
                    COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.sales_bills END), 0) AS month_nob,
                    COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.sales_customers END), 0) AS month_noc,
                    COALESCE(SUM(CASE WHEN x.entry_date = ? THEN x.sales_value END), 0) AS today,
                    COALESCE(SUM(CASE WHEN x.entry_date = ? THEN x.sales_bills END), 0) AS today_nob,
                    COALESCE(SUM(CASE WHEN x.entry_date = ? THEN x.sales_customers END), 0) AS today_noc
             FROM rep_daily_totals x WHERE x.entry_date BETWEEN ? AND ? AND {$w} GROUP BY x.{$dim}",
            array_merge([$this->fyStart, $this->prevDay, $this->monthStart, $this->prevDay, $this->monthStart, $this->prevDay,
                         $this->monthStart, $this->prevDay, $this->asOn, $this->asOn, $this->asOn, $this->fyStart, $this->asOn], $p)
        );
        $flows = array_column($flows, null, 'k');

        [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
        $targets = Database::fetchAll(
            "SELECT t.{$dim} AS k, SUM(t.sales_target) AS annual,
                    COALESCE(SUM(CASE WHEN t.target_month = ? THEN t.sales_target END), 0) AS month
             FROM sales_targets t WHERE t.financial_year_id = ? AND {$tw} GROUP BY t.{$dim}",
            array_merge([$this->monthStart, $this->ctx->fyRow['id']], $tp)
        );
        $targets = array_column($targets, null, 'k');

        $keys = array_unique(array_merge(array_keys($flows), array_keys($targets)));
        $labels = $this->labels($by, $keys);
        $rows = [];
        foreach ($keys as $k) {
            $rows[] = $this->salesRow((int) $k, $labels[$k] ?? '?', $flows[$k] ?? [], $targets[$k] ?? []);
        }
        usort($rows, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        $total = ['label' => 'Total'];
        foreach (['annual_target', 'fy_prev', 'month_target', 'month_prev', 'month_nob', 'month_noc', 'today', 'today_nob', 'today_noc'] as $c) {
            $total[$c] = array_sum(array_column($rows, $c));
        }
        $total['month_pct'] = $total['month_target'] > 0 ? round($total['month_prev'] * 100 / $total['month_target'], 1) : null;
        $total['fy_pct'] = $total['annual_target'] > 0 ? round($total['fy_prev'] * 100 / $total['annual_target'], 1) : null;
        return ['rows' => $rows, 'total' => $total, 'by' => $by];
    }

    /** @param array<string, mixed> $f @param array<string, mixed> $t */
    private function salesRow(int $id, string $label, array $f, array $t): array
    {
        $row = [
            'id' => $id, 'label' => $label,
            'annual_target' => Money::fromDb($t['annual'] ?? null),
            'fy_prev'       => Money::fromDb($f['fy_prev'] ?? null),
            'month_target'  => Money::fromDb($t['month'] ?? null),
            'month_prev'    => Money::fromDb($f['month_prev'] ?? null),
            'month_nob'     => (int) ($f['month_nob'] ?? 0),
            'month_noc'     => (int) ($f['month_noc'] ?? 0),
            'today'         => Money::fromDb($f['today'] ?? null),
            'today_nob'     => (int) ($f['today_nob'] ?? 0),
            'today_noc'     => (int) ($f['today_noc'] ?? 0),
        ];
        $row['month_pct'] = $row['month_target'] > 0 ? round($row['month_prev'] * 100 / $row['month_target'], 1) : null;
        $row['fy_pct'] = $row['annual_target'] > 0 ? round($row['fy_prev'] * 100 / $row['annual_target'], 1) : null;
        return $row;
    }

    // =========================================================================
    // 2-4. Positions and month figures
    // =========================================================================

    /**
     * Latest entered position per rep (on or before as-on), summed.
     *
     * @return array{totals: array<string, int>, reps: list<array<string, mixed>>}
     */
    public function positions(): array
    {
        [$w, $p] = $this->where('x');
        $cols = implode(', ', array_map(static fn ($c) => "x.{$c}", self::POSITIONS));
        $rows = Database::fetchAll(
            "SELECT x.employee_id, x.entry_date, {$cols} FROM rep_daily_totals x
             WHERE x.entry_date BETWEEN ? AND ? AND {$w} ORDER BY x.employee_id, x.entry_date DESC",
            array_merge([$this->fyStart, $this->asOn], $p)
        );
        $latest = [];          // employee => field => [value, date]
        foreach ($rows as $r) {
            $e = (int) $r['employee_id'];
            foreach (self::POSITIONS as $c) {
                if ($r[$c] !== null && !isset($latest[$e][$c])) {
                    $latest[$e][$c] = [in_array($c, self::COUNT_POSITIONS, true) ? (int) $r[$c] : Money::fromDb($r[$c]), $r['entry_date']];
                }
            }
        }
        $totals = array_fill_keys(self::POSITIONS, 0);
        $reps = [];
        $labels = $this->labels('employee', array_keys($latest));
        foreach ($latest as $e => $fields) {
            $rep = ['employee_id' => $e, 'label' => $labels[$e] ?? '?', 'updated' => max(array_column($fields, 1))];
            foreach (self::POSITIONS as $c) {
                $rep[$c] = $fields[$c][0] ?? 0;
                $rep[$c . '_date'] = $fields[$c][1] ?? null;
                $totals[$c] += $rep[$c];
            }
            $reps[] = $rep;
        }
        usort($reps, static fn ($a, $b) => strcmp($a['label'], $b['label']));
        return ['totals' => $totals, 'reps' => $reps];
    }

    /** Leads created this month (1st .. as-on, including today). @return array{new_customer: int, new_product: int} */
    public function leadsThisMonth(): array
    {
        [$w, $p] = $this->where('x');
        $r = Database::fetch(
            "SELECT COALESCE(SUM(x.lead_new_customer), 0) AS c, COALESCE(SUM(x.lead_new_product), 0) AS p
             FROM rep_daily_totals x WHERE x.entry_date BETWEEN ? AND ? AND {$w}",
            array_merge([$this->monthStart, $this->asOn], $p)
        );
        return ['new_customer' => (int) $r['c'], 'new_product' => (int) $r['p']];
    }

    /** @return array<string, mixed> */
    public function collection(): array
    {
        [$w, $p] = $this->where('x');
        $c = Database::fetch(
            "SELECT COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.collection_value END), 0) AS month_prev,
                    COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.collection_bills END), 0) AS month_nob,
                    COALESCE(SUM(CASE WHEN x.entry_date BETWEEN ? AND ? THEN x.collection_customers END), 0) AS month_noc,
                    COALESCE(SUM(CASE WHEN x.entry_date = ? THEN x.collection_value END), 0) AS today,
                    COALESCE(SUM(CASE WHEN x.entry_date = ? THEN x.collection_bills END), 0) AS today_nob,
                    COALESCE(SUM(CASE WHEN x.entry_date = ? THEN x.collection_customers END), 0) AS today_noc
             FROM rep_daily_totals x WHERE x.entry_date BETWEEN ? AND ? AND {$w}",
            array_merge([$this->monthStart, $this->prevDay, $this->monthStart, $this->prevDay, $this->monthStart, $this->prevDay,
                         $this->asOn, $this->asOn, $this->asOn, $this->monthStart, $this->asOn], $p)
        );
        [$ow, $op] = $this->ctx->where(['branch' => 'o.branch_id', 'employee' => 'o.employee_id']);
        $opening = Money::fromDb(Database::value(
            "SELECT SUM(o.opening_outstanding) FROM rep_month_openings o WHERE o.opening_month = ? AND {$ow}",
            array_merge([$this->monthStart], $op)
        ));
        $openingReps = (int) Database::value("SELECT COUNT(*) FROM rep_month_openings o WHERE o.opening_month = ? AND {$ow}", array_merge([$this->monthStart], $op));
        [$tw, $tp] = $this->ctx->where(['branch' => 't.branch_id', 'employee' => 't.employee_id']);
        $target = Money::fromDb(Database::value(
            "SELECT SUM(t.collection_target) FROM sales_targets t WHERE t.target_month = ? AND {$tw}",
            array_merge([$this->monthStart], $tp)
        ));
        $monthPrev = Money::fromDb($c['month_prev']);
        return [
            'opening'        => $opening,
            'opening_reps'   => $openingReps,
            'month_prev'     => $monthPrev,
            'month_nob'      => (int) $c['month_nob'],
            'month_noc'      => (int) $c['month_noc'],
            'month_target'   => $target,
            'pct_target'     => $target > 0 ? round($monthPrev * 100 / $target, 1) : null,
            'pct_opening'    => $opening > 0 ? round($monthPrev * 100 / $opening, 1) : null,
            'today'          => Money::fromDb($c['today']),
            'today_nob'      => (int) $c['today_nob'],
            'today_noc'      => (int) $c['today_noc'],
        ];
    }

    // -------------------------------------------------------------------------

    /** Data scope + branch / employee filters on a rep_daily_totals alias. @return array{0: string, 1: list<mixed>} */
    private function where(string $a): array
    {
        return $this->ctx->where(['branch' => "{$a}.branch_id", 'employee' => "{$a}.employee_id"]);
    }

    /** @param list<int|string> $ids @return array<int, string> */
    private function labels(string $by, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = $by === 'branch'
            ? "SELECT id, name FROM branches WHERE id IN ({$in})"
            : "SELECT id, CONCAT(COALESCE(short_name, name), ' - ', name) FROM employees WHERE id IN ({$in})";
        return Database::query($sql, $ids)->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
