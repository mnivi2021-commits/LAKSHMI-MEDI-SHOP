<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class SalesByCustomer extends Report
{
    public function key(): string { return 'sales-by-customer'; }
    public function title(): string { return 'Sales by customer'; }
    public function group(): string { return 'Sales'; }
    public function description(): string { return 'Customer-wise sales in the period, highest first.'; }
    public function permission(): string { return 'sales.view'; }
    public function filters(): array { return ['period', 'branch', 'employee']; }

    public function columns(): array
    {
        return ['customer' => ['Customer', 'text'], 'code' => ['Code', 'text'], 'branch' => ['Branch', 'text'], 'documents' => ['Documents', 'int'],
                'taxable' => ['Taxable', 'money'], 'total' => ['Total', 'money']];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('v.branch_id', 'v.employee_id', 'v.customer_id');
        return array_map(static fn ($r) => [
            'customer' => $r['name'], 'code' => $r['customer_code'], 'branch' => $r['branch_code'], 'documents' => (int) $r['docs'],
            'taxable' => self::paise($r['taxable']), 'total' => self::paise($r['total']),
        ], Database::fetchAll(
            "SELECT c.name, c.customer_code, b.branch_code, COUNT(*) AS docs, SUM(v.taxable_value) AS taxable, SUM(v.total_value) AS total
             FROM v_sales_documents v JOIN customers c ON c.id = v.customer_id JOIN branches b ON b.id = c.branch_id
             WHERE v.invoice_date BETWEEN ? AND ? AND {$w}
             GROUP BY c.id, c.name, c.customer_code, b.branch_code ORDER BY SUM(v.{$this->col()}) DESC",
            array_merge([$f->from, $f->to], $p)
        ));
    }

    private function col(): string
    {
        return self::salesCol();
    }
}
