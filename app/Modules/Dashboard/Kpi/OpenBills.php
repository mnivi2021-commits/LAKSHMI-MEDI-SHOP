<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Settings;

/**
 * The one definition of "open (unpaid) bills", used by Collection (overdue),
 * Outstanding 90/150 days, Sales Representative and Customer drill-downs.
 *
 * settings outstanding.source:
 *   computed  invoices - valid receipt allocations - credit notes (v_invoice_balances)
 *   imported  latest bill-wise statement uploaded from the accounting system
 *
 * Both are exposed with identical columns:
 *   bill_key, invoice_id (null when imported), invoice_no, invoice_date, due_date,
 *   customer_id, branch_id, employee_id, bill_amount, balance
 */
final class OpenBills
{
    public static function source(): string
    {
        return Settings::get('outstanding', 'source', 'computed') === 'imported' ? 'imported' : 'computed';
    }

    /** Derived-table SQL (use as: FROM {OpenBills::sql()} ob). Contains no user input. */
    public static function sql(): string
    {
        if (self::source() === 'imported') {
            return "(SELECT CONCAT('ob-', o.id) AS bill_key, NULL AS invoice_id, o.invoice_no, o.invoice_date,
                            COALESCE(o.due_date, DATE_ADD(o.invoice_date, INTERVAL c.credit_days DAY)) AS due_date,
                            o.customer_id, o.branch_id, o.employee_id, o.bill_amount, o.pending_amount AS balance
                     FROM v_outstanding_latest o JOIN customers c ON c.id = o.customer_id)";
        }
        return "(SELECT CONCAT('inv-', b.invoice_id) AS bill_key, b.invoice_id, b.invoice_no, b.invoice_date, b.due_date,
                        b.customer_id, b.branch_id, b.employee_id, b.total_amount AS bill_amount, b.balance
                 FROM v_invoice_balances b WHERE b.balance > 0)";
    }

    /** Column the bill age is measured from (settings outstanding.aging_basis). */
    public static function ageColumn(string $alias = 'ob'): string
    {
        return $alias . '.' . (Settings::get('outstanding', 'aging_basis', 'invoice_date') === 'due_date' ? 'due_date' : 'invoice_date');
    }

    /** Human label for the age basis. */
    public static function ageBasisLabel(): string
    {
        return Settings::get('outstanding', 'aging_basis', 'invoice_date') === 'due_date' ? 'days past due date' : 'days since invoice date';
    }
}
