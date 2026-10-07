<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

/** Sales details: every invoice line with rep, invoice no, customer, product and price. */
final class SalesDetails extends Report
{
    public function key(): string { return 'sales-details'; }
    public function title(): string { return 'Sales details'; }
    public function group(): string { return 'Rep-wise details'; }
    public function description(): string { return 'Every invoice line: rep, invoice no, customer, PO ref, product, quantity, price and value.'; }
    public function permission(): string { return 'sales.view'; }
    public function filters(): array { return ['period', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['rep' => ['Rep', 'text'], 'date' => ['Date', 'date'], 'doc' => ['Invoice no', 'text'], 'customer' => ['Customer', 'text'],
                'po' => ['P.O ref', 'text'], 'product' => ['Product', 'text'], 'qty' => ['Quantity', 'qty'], 'price' => ['Price', 'money'],
                'value' => ['Value', 'money']];
    }

    public function noTotal(): array
    {
        return ['qty', 'price'];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('v.branch_id', 'v.employee_id', 'v.customer_id');
        $col = self::salesCol();
        return array_map(static fn ($r) => [
            'rep' => $r['rep'] ?? '', 'date' => $r['invoice_date'],
            'doc' => $r['invoice_no'] . ($r['document_type'] === 'credit_note' ? ' (credit note)' : ''),
            'customer' => "{$r['customer']} ({$r['customer_code']})", 'po' => $r['customer_po_no'] ?? '', 'product' => $r['product'],
            'qty' => rtrim(rtrim((string) $r['quantity'], '0'), '.') ?: '0', 'price' => self::paise($r['rate']), 'value' => self::paise($r['value']),
        ], Database::fetchAll(
            "SELECT v.invoice_date, v.invoice_no, v.document_type, v.quantity, v.{$col} AS value, it.rate, si.customer_po_no,
                    c.name AS customer, c.customer_code, pr.name AS product, COALESCE(e.short_name, e.name) AS rep
             FROM v_sales_lines v
             JOIN sales_invoice_items it ON it.id = v.item_id
             JOIN sales_invoices si ON si.id = v.invoice_id
             JOIN customers c ON c.id = v.customer_id
             JOIN products pr ON pr.id = v.product_id
             LEFT JOIN employees e ON e.id = v.employee_id
             WHERE v.invoice_date BETWEEN ? AND ? AND {$w}
             ORDER BY v.invoice_date, v.invoice_no, it.id",
            array_merge([$f->from, $f->to], $p)
        ));
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Credit notes are shown as negative lines. Value is ' . (self::salesCol() === 'total_value' ? 'including' : 'before') . ' GST, as on the dashboard.';
    }
}
