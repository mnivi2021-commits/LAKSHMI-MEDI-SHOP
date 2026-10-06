<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Dashboard\Kpi\Aging;
use App\Modules\Dashboard\Kpi\OpenBills;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class OutstandingAgeing extends Report
{
    public function key(): string { return 'outstanding-ageing'; }
    public function title(): string { return 'Outstanding ageing'; }
    public function group(): string { return 'Collection'; }
    public function description(): string { return 'Unpaid bills per customer split by age, as on a date.'; }
    public function permission(): string { return 'outstanding.view'; }
    public function filters(): array { return ['as_on', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        $cols = ['customer' => ['Customer', 'text'], 'code' => ['Code', 'text'], 'employee' => ['Employee', 'text'], 'bills' => ['Bills', 'int']];
        foreach (Aging::buckets() as $b) {
            $cols['b_' . $b['key']] = [$b['label'], 'money'];
        }
        $cols['total'] = ['Total', 'money'];
        $cols['oldest'] = ['Oldest (days)', 'int'];
        return $cols;
    }

    public function noTotal(): array
    {
        return ['oldest'];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('ob.branch_id', 'ob.employee_id', 'ob.customer_id');
        $bills = Database::fetchAll(
            'SELECT ob.customer_id, ob.balance, DATEDIFF(?, ' . OpenBills::ageColumn('ob') . ') AS age, cu.name, cu.customer_code, e.short_name AS employee
             FROM ' . OpenBills::sql() . " ob JOIN customers cu ON cu.id = ob.customer_id LEFT JOIN employees e ON e.id = cu.employee_id
             WHERE ob.invoice_date <= ? AND {$w}",
            array_merge([$f->asOn, $f->asOn], $p)
        );
        $buckets = Aging::buckets();
        $rows = [];
        foreach ($bills as $b) {
            $id = (int) $b['customer_id'];
            if (!isset($rows[$id])) {
                $rows[$id] = ['customer' => $b['name'], 'code' => $b['customer_code'], 'employee' => $b['employee'] ?? '', 'bills' => 0];
                foreach ($buckets as $bk) {
                    $rows[$id]['b_' . $bk['key']] = 0;
                }
                $rows[$id] += ['total' => 0, 'oldest' => 0];
            }
            $age = max(0, (int) $b['age']);
            foreach ($buckets as $bk) {
                if ($bk['max'] === null || $age <= $bk['max']) {
                    $rows[$id]['b_' . $bk['key']] += self::paise($b['balance']);
                    break;
                }
            }
            $rows[$id]['bills']++;
            $rows[$id]['total'] += self::paise($b['balance']);
            $rows[$id]['oldest'] = max($rows[$id]['oldest'], $age);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => $b['total'] <=> $a['total']);
        return $rows;
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Age is counted in ' . OpenBills::ageBasisLabel() . '. Source: ' . (OpenBills::source() === 'imported' ? 'latest imported outstanding statement.' : 'invoices minus receipts and credit notes.');
    }
}
