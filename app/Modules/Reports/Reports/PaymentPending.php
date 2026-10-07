<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Dashboard\Kpi\OpenBills;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

/** Payment pending details: every open bill with rep, invoice, PO ref, value, balance and due date. */
final class PaymentPending extends Report
{
    public function key(): string { return 'payment-pending'; }
    public function title(): string { return 'Payment pending details'; }
    public function group(): string { return 'Rep-wise details'; }
    public function description(): string { return 'Every unpaid bill: rep, customer, invoice no, bill date, PO ref, invoice value, due balance and due date.'; }
    public function permission(): string { return 'outstanding.view'; }
    public function filters(): array { return ['branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['rep' => ['Rep', 'text'], 'customer' => ['Customer', 'text'], 'doc' => ['Invoice no', 'text'], 'date' => ['Bill date', 'date'],
                'po' => ['P.O ref', 'text'], 'value' => ['Invoice value', 'money'], 'balance' => ['Due balance', 'money'],
                'due' => ['Due date', 'date'], 'overdue' => ['Overdue days', 'int']];
    }

    public function noTotal(): array
    {
        return ['overdue'];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('ob.branch_id', 'ob.employee_id', 'ob.customer_id');
        $bills = OpenBills::sql();
        return array_map(static fn ($r) => [
            'rep' => $r['rep'] ?? '', 'customer' => "{$r['customer']} ({$r['customer_code']})", 'doc' => $r['invoice_no'], 'date' => $r['invoice_date'],
            'po' => $r['po_ref'] ?? '', 'value' => self::paise($r['bill_amount']), 'balance' => self::paise($r['balance']), 'due' => $r['due_date'],
            'overdue' => max(0, (int) $r['overdue']),
        ], Database::fetchAll(
            "SELECT ob.*, c.name AS customer, c.customer_code, COALESCE(e.short_name, e.name) AS rep, DATEDIFF(CURDATE(), ob.due_date) AS overdue,
                    (SELECT si.customer_po_no FROM sales_invoices si
                     WHERE si.id = ob.invoice_id
                        OR (ob.invoice_id IS NULL AND si.invoice_no = ob.invoice_no AND si.customer_id = ob.customer_id
                            AND si.document_type = 'invoice' AND si.deleted_at IS NULL)
                     ORDER BY si.invoice_date DESC LIMIT 1) AS po_ref
             FROM {$bills} ob JOIN customers c ON c.id = ob.customer_id LEFT JOIN employees e ON e.id = ob.employee_id
             WHERE {$w}
             ORDER BY rep, c.name, ob.invoice_date, ob.invoice_no",
            $p
        ));
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Open bills as of today (' . (OpenBills::source() === 'imported' ? 'latest outstanding statement uploaded' : 'CRM invoices less receipts and credit notes') . ').';
    }
}
