<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class TargetAchievement extends Report
{
    public function key(): string { return 'target-achievement'; }
    public function title(): string { return 'Target vs achievement'; }
    public function group(): string { return 'Sales'; }
    public function description(): string { return 'Sales and collection against target for each sales employee in the period.'; }
    public function permission(): string { return 'targets.view'; }
    public function filters(): array { return ['period', 'branch', 'employee']; }

    public function columns(): array
    {
        return ['employee' => ['Employee', 'text'], 'branch' => ['Branch', 'text'],
                'sales_target' => ['Sales target', 'money'], 'sales' => ['Sales', 'money'], 'sales_pct' => ['Achieved', 'pct'],
                'coll_target' => ['Collection target', 'money'], 'collection' => ['Collection', 'money'], 'coll_pct' => ['Collected', 'pct']];
    }

    public function rows(ReportFilters $f): array
    {
        $monthFrom = substr($f->from, 0, 8) . '01';
        [$wt, $pt] = $f->where('t.branch_id', 't.employee_id');
        [$ws, $ps] = $f->where('v.branch_id', 'v.employee_id');
        [$wc, $pc] = $f->where('c.branch_id', 'c.employee_id');
        $col = self::salesCol();
        $targets = Database::fetchAll("SELECT t.employee_id, SUM(t.sales_target) AS st, SUM(t.collection_target) AS ct FROM sales_targets t
                                       WHERE t.target_month BETWEEN ? AND ? AND {$wt} GROUP BY t.employee_id", array_merge([$monthFrom, $f->to], $pt));
        $sales = Database::query("SELECT v.employee_id, SUM(v.{$col}) FROM v_sales_documents v WHERE v.invoice_date BETWEEN ? AND ? AND v.employee_id IS NOT NULL AND {$ws} GROUP BY v.employee_id",
            array_merge([$f->from, $f->to], $ps))->fetchAll(\PDO::FETCH_KEY_PAIR);
        $coll = Database::query("SELECT c.employee_id, SUM(c.amount) FROM v_valid_collections c WHERE c.receipt_date BETWEEN ? AND ? AND c.employee_id IS NOT NULL AND {$wc} GROUP BY c.employee_id",
            array_merge([$f->from, $f->to], $pc))->fetchAll(\PDO::FETCH_KEY_PAIR);

        $ids = array_unique(array_merge(array_column($targets, 'employee_id'), array_keys($sales), array_keys($coll)));
        $t = array_column($targets, null, 'employee_id');
        $rows = [];
        foreach ($ids as $id) {
            $e = Database::fetch('SELECT e.name, e.short_name, b.branch_code FROM employees e JOIN branches b ON b.id = e.branch_id WHERE e.id = ?', [$id]);
            $st = self::paise($t[$id]['st'] ?? null);
            $ct = self::paise($t[$id]['ct'] ?? null);
            $s = self::paise($sales[$id] ?? null);
            $c = self::paise($coll[$id] ?? null);
            $rows[] = ['employee' => trim(($e['short_name'] ?? '') . ' - ' . ($e['name'] ?? '?'), ' -'), 'branch' => $e['branch_code'] ?? '',
                'sales_target' => $st, 'sales' => $s, 'sales_pct' => $st > 0 ? round($s * 100 / $st, 1) : null,
                'coll_target' => $ct, 'collection' => $c, 'coll_pct' => $ct > 0 ? round($c * 100 / $ct, 1) : null];
        }
        usort($rows, static fn ($a, $b) => [$b['sales_pct'] ?? -1, $b['sales']] <=> [$a['sales_pct'] ?? -1, $a['sales']]);
        return $rows;
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Targets are monthly: every month that starts within the period counts in full (from ' . date('M Y', strtotime($f->from)) . ' to ' . date('M Y', strtotime($f->to)) . ').';
    }
}
