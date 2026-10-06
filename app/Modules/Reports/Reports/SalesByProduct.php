<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class SalesByProduct extends Report
{
    public function key(): string { return 'sales-by-product'; }
    public function title(): string { return 'Sales by product'; }
    public function group(): string { return 'Sales'; }
    public function description(): string { return 'Quantity and value sold per product in the period.'; }
    public function permission(): string { return 'sales.view'; }
    public function filters(): array { return ['period', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['product' => ['Product', 'text'], 'code' => ['Code', 'text'], 'unit' => ['Unit', 'text'], 'qty' => ['Quantity', 'qty'],
                'customers' => ['Customers', 'int'], 'taxable' => ['Taxable', 'money'], 'total' => ['Total', 'money']];
    }

    public function noTotal(): array
    {
        return ['qty', 'customers'];      // different units / the same customer buys several products
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('v.branch_id', 'v.employee_id', 'v.customer_id');
        return array_map(static fn ($r) => [
            'product' => $r['name'], 'code' => $r['product_code'], 'unit' => $r['unit'], 'qty' => rtrim(rtrim((string) $r['qty'], '0'), '.') ?: '0',
            'customers' => (int) $r['customers'], 'taxable' => self::paise($r['taxable']), 'total' => self::paise($r['total']),
        ], Database::fetchAll(
            "SELECT pr.name, pr.product_code, pr.unit, SUM(v.quantity) AS qty, COUNT(DISTINCT v.customer_id) AS customers,
                    SUM(v.taxable_value) AS taxable, SUM(v.total_value) AS total
             FROM v_sales_lines v JOIN products pr ON pr.id = v.product_id
             WHERE v.invoice_date BETWEEN ? AND ? AND {$w}
             GROUP BY pr.id, pr.name, pr.product_code, pr.unit ORDER BY SUM(v.taxable_value) DESC",
            array_merge([$f->from, $f->to], $p)
        ));
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Only invoices entered with product lines appear here; invoices imported without products count in the sales register but not by product.';
    }
}
