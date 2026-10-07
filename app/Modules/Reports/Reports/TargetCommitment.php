<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

/**
 * Target commitment: each rep's monthly sales target against what was sold that month.
 * Closed = target reached; Open = still short (balance to sell).
 */
final class TargetCommitment extends Report
{
    public function key(): string { return 'target-commitment'; }
    public function title(): string { return 'Target commitment'; }
    public function group(): string { return 'Rep-wise details'; }
    public function description(): string { return 'Monthly target per rep: achieved, balance and whether the commitment is Closed (reached) or Open.'; }
    public function permission(): string { return 'targets.view'; }
    public function filters(): array { return ['period', 'branch', 'employee']; }

    public function columns(): array
    {
        return ['rep' => ['Rep', 'text'], 'branch' => ['Branch', 'text'], 'month' => ['Month', 'text'], 'target' => ['Target', 'money'],
                'achieved' => ['Achieved', 'money'], 'balance' => ['Balance', 'money'], 'pct' => ['Achieved %', 'pct'], 'status' => ['Status', 'text']];
    }

    public function rows(ReportFilters $f): array
    {
        $monthFrom = substr($f->from, 0, 8) . '01';
        [$wt, $pt] = $f->where('t.branch_id', 't.employee_id');
        $col = self::salesCol();
        $rows = [];
        foreach (Database::fetchAll(
            "SELECT t.employee_id, t.target_month, SUM(t.sales_target) AS target, COALESCE(e.short_name, e.name) AS rep, e.name, b.branch_code
             FROM sales_targets t JOIN employees e ON e.id = t.employee_id JOIN branches b ON b.id = t.branch_id
             WHERE t.target_month BETWEEN ? AND ? AND {$wt}
             GROUP BY t.employee_id, t.target_month, e.short_name, e.name, b.branch_code
             ORDER BY t.target_month, rep",
            array_merge([$monthFrom, $f->to], $pt)) as $t) {
            $end = date('Y-m-t', strtotime($t['target_month']));
            $sold = self::paise(Database::value(
                "SELECT SUM(v.{$col}) FROM v_sales_documents v WHERE v.employee_id = ? AND v.invoice_date BETWEEN ? AND ?",
                [$t['employee_id'], $t['target_month'], min($end, $f->to)]));
            $target = self::paise($t['target']);
            $rows[] = [
                'rep' => "{$t['rep']} - {$t['name']}", 'branch' => $t['branch_code'], 'month' => date('M Y', strtotime($t['target_month'])),
                'target' => $target, 'achieved' => $sold, 'balance' => max(0, $target - $sold),
                'pct' => $target > 0 ? round($sold * 100 / $target, 1) : null, 'status' => $target > 0 && $sold >= $target ? 'Closed' : 'Open',
            ];
        }
        return $rows;
    }

    public function noTotal(): array
    {
        return [];
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Closed = the month\'s sales reached the target; Open = still short by the balance. Sales up to ' . date('d-m-Y', strtotime($f->to)) . '.';
    }
}
