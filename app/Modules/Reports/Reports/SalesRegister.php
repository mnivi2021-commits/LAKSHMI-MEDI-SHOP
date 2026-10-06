<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class SalesRegister extends Report
{
    public function key(): string { return 'sales-register'; }
    public function title(): string { return 'Sales register'; }
    public function group(): string { return 'Sales'; }
    public function description(): string { return 'Every invoice and credit note in the period, with taxable value, GST and total.'; }
    public function permission(): string { return 'sales.view'; }
    public function filters(): array { return ['period', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['date' => ['Date', 'date'], 'doc' => ['Document', 'text'], 'type' => ['Type', 'text'], 'customer' => ['Customer', 'text'],
                'branch' => ['Branch', 'text'], 'employee' => ['Employee', 'text'],
                'taxable' => ['Taxable', 'money'], 'tax' => ['GST', 'money'], 'total' => ['Total', 'money']];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('v.branch_id', 'v.employee_id', 'v.customer_id');
        return array_map(static fn ($r) => [
            'date' => $r['invoice_date'], 'doc' => $r['invoice_no'], 'type' => $r['document_type'] === 'credit_note' ? 'Credit note' : 'Invoice',
            'customer' => "{$r['customer']} ({$r['customer_code']})", 'branch' => $r['branch_code'], 'employee' => $r['employee'] ?? '',
            'taxable' => self::paise($r['taxable_value']), 'tax' => self::paise($r['tax_value']), 'total' => self::paise($r['total_value']),
        ], Database::fetchAll(
            "SELECT v.*, c.name AS customer, c.customer_code, b.branch_code, e.short_name AS employee
             FROM v_sales_documents v JOIN customers c ON c.id = v.customer_id JOIN branches b ON b.id = v.branch_id
             LEFT JOIN employees e ON e.id = v.employee_id
             WHERE v.invoice_date BETWEEN ? AND ? AND {$w} ORDER BY v.invoice_date, v.invoice_no",
            array_merge([$f->from, $f->to], $p)
        ));
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Cancelled and deleted invoices are excluded; credit notes are negative. The dashboard counts sales on the ' . (self::salesCol() === 'total_value' ? 'Total' : 'Taxable') . ' column.';
    }
}
