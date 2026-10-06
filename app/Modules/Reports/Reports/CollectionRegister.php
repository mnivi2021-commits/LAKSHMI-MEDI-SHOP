<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class CollectionRegister extends Report
{
    private const MODES = ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'dd' => 'DD', 'other' => 'Other'];

    public function key(): string { return 'collection-register'; }
    public function title(): string { return 'Collection register'; }
    public function group(): string { return 'Collection'; }
    public function description(): string { return 'Every valid receipt in the period and how much of it is adjusted against bills.'; }
    public function permission(): string { return 'collections.view'; }
    public function filters(): array { return ['period', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['date' => ['Date', 'date'], 'receipt' => ['Receipt', 'text'], 'customer' => ['Customer', 'text'], 'mode' => ['Mode', 'text'],
                'reference' => ['Reference', 'text'], 'employee' => ['Employee', 'text'],
                'amount' => ['Amount', 'money'], 'allocated' => ['Adjusted to bills', 'money'], 'on_account' => ['On account', 'money']];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('c.branch_id', 'c.employee_id', 'c.customer_id');
        return array_map(static fn ($r) => [
            'date' => $r['receipt_date'], 'receipt' => $r['receipt_no'], 'customer' => "{$r['customer']} ({$r['customer_code']})",
            'mode' => self::MODES[$r['payment_mode']] ?? $r['payment_mode'], 'reference' => $r['reference_no'] ?? '', 'employee' => $r['employee'] ?? '',
            'amount' => self::paise($r['amount']), 'allocated' => self::paise($r['allocated_amount']), 'on_account' => self::paise($r['unallocated_amount']),
        ], Database::fetchAll(
            "SELECT c.*, cu.name AS customer, cu.customer_code, e.short_name AS employee
             FROM v_valid_collections c JOIN customers cu ON cu.id = c.customer_id LEFT JOIN employees e ON e.id = c.employee_id
             WHERE c.receipt_date BETWEEN ? AND ? AND {$w} ORDER BY c.receipt_date, c.receipt_no",
            array_merge([$f->from, $f->to], $p)
        ));
    }

    public function note(ReportFilters $f): ?string
    {
        return 'Bounced and cancelled receipts are excluded, exactly as on the dashboard.';
    }
}
