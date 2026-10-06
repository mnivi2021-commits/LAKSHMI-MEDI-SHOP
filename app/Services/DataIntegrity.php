<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Cross-table business rules that MySQL CHECK / FOREIGN KEY constraints cannot
 * express. Run after every import and from cli/verify-data.php; also surfaced on
 * the health page. Each rule returns the offending record ids so they can be fixed.
 *
 * severity: error   = a dashboard figure is (or can be) wrong
 *           warning = suspicious data worth reviewing
 */
final class DataIntegrity
{
    /** @return list<array{code: string, severity: string, title: string, sql: string}> */
    public static function rules(): array
    {
        $fyRule = static fn (string $table, string $dateCol, string $label): array => [
            'code'     => "fy_mismatch_{$table}",
            'severity' => 'error',
            'title'    => "{$label} dated outside its financial year",
            'sql'      => "SELECT t.id FROM {$table} t JOIN financial_years fy ON fy.id = t.financial_year_id
                           WHERE t.{$dateCol} NOT BETWEEN fy.start_date AND fy.end_date",
        ];

        return [
            $fyRule('sales_invoices', 'invoice_date', 'Invoice / credit note'),
            $fyRule('collections', 'receipt_date', 'Receipt'),
            $fyRule('pending_orders', 'order_date', 'Pending order'),
            $fyRule('samples', 'document_date', 'Sample'),
            $fyRule('dc_records', 'dc_date', 'Delivery challan'),
            $fyRule('sales_targets', 'target_month', 'Sales target month'),
            [
                'code'     => 'invoice_over_settled',
                'severity' => 'error',
                'title'    => 'Invoice paid/credited more than its total (negative balance)',
                'sql'      => 'SELECT invoice_id FROM v_invoice_balances WHERE balance < 0',
            ],
            [
                'code'     => 'receipt_over_allocated',
                'severity' => 'error',
                'title'    => 'Receipt allocated to invoices for more than the amount received',
                'sql'      => 'SELECT c.id FROM collections c JOIN collection_allocations a ON a.collection_id = c.id
                               GROUP BY c.id, c.amount HAVING SUM(a.amount) > c.amount',
            ],
            [
                'code'     => 'allocation_customer_mismatch',
                'severity' => 'error',
                'title'    => "Receipt allocated to another customer's invoice",
                'sql'      => 'SELECT a.id FROM collection_allocations a
                               JOIN collections c ON c.id = a.collection_id
                               JOIN sales_invoices i ON i.id = a.invoice_id
                               WHERE c.customer_id <> i.customer_id',
            ],
            [
                'code'     => 'allocation_to_credit_note',
                'severity' => 'error',
                'title'    => 'Receipt allocated to a credit note instead of an invoice',
                'sql'      => "SELECT a.id FROM collection_allocations a JOIN sales_invoices i ON i.id = a.invoice_id
                               WHERE i.document_type = 'credit_note'",
            ],
            [
                'code'     => 'allocation_on_void_receipt',
                'severity' => 'warning',
                'title'    => 'Allocation still attached to a bounced / cancelled / deleted receipt (ignored in balances)',
                'sql'      => "SELECT a.id FROM collection_allocations a JOIN collections c ON c.id = a.collection_id
                               WHERE c.status IN ('bounced','cancelled') OR c.deleted_at IS NOT NULL",
            ],
            [
                'code'     => 'credit_note_bad_reference',
                'severity' => 'error',
                'title'    => 'Credit note not linked to an invoice of the same customer',
                'sql'      => "SELECT cn.id FROM sales_invoices cn
                               LEFT JOIN sales_invoices inv ON inv.id = cn.reference_invoice_id
                               WHERE cn.document_type = 'credit_note'
                                 AND (inv.id IS NULL OR inv.document_type <> 'invoice' OR inv.customer_id <> cn.customer_id)",
            ],
            [
                'code'     => 'invoice_reference_on_invoice',
                'severity' => 'warning',
                'title'    => 'Invoice (not credit note) has a reference invoice set',
                'sql'      => "SELECT id FROM sales_invoices WHERE document_type = 'invoice' AND reference_invoice_id IS NOT NULL",
            ],
            [
                'code'     => 'invoice_total_mismatch',
                'severity' => 'warning',
                'title'    => 'Invoice total differs from taxable + tax + round-off by more than ₹1',
                'sql'      => 'SELECT id FROM sales_invoices
                               WHERE ABS(total_amount - (taxable_amount + tax_amount + round_off)) > 1',
            ],
            [
                'code'     => 'invoice_items_mismatch',
                'severity' => 'warning',
                'title'    => 'Invoice taxable value differs from the sum of its line items by more than ₹1',
                'sql'      => 'SELECT i.id FROM sales_invoices i JOIN sales_invoice_items it ON it.invoice_id = i.id
                               GROUP BY i.id, i.taxable_amount HAVING ABS(i.taxable_amount - SUM(it.taxable_amount)) > 1',
            ],
            [
                'code'     => 'invoice_without_items',
                'severity' => 'warning',
                'title'    => 'Active invoice has no line items (missing from product reports)',
                'sql'      => "SELECT i.id FROM sales_invoices i
                               WHERE i.status = 'active' AND i.deleted_at IS NULL
                                 AND NOT EXISTS (SELECT 1 FROM sales_invoice_items it WHERE it.invoice_id = i.id)",
            ],
            [
                'code'     => 'future_dated_transaction',
                'severity' => 'warning',
                'title'    => 'Invoice or receipt dated in the future',
                'sql'      => 'SELECT id FROM sales_invoices WHERE invoice_date > CURDATE()
                               UNION ALL SELECT id FROM collections WHERE receipt_date > CURDATE()',
            ],
            [
                'code'     => 'order_closed_with_balance',
                'severity' => 'warning',
                'title'    => 'Order marked closed but still has a pending balance (excluded from pending KPI)',
                'sql'      => "SELECT o.id FROM pending_orders o JOIN pending_order_items i ON i.order_id = o.id
                               WHERE o.status = 'closed' AND o.deleted_at IS NULL
                               GROUP BY o.id HAVING SUM(i.pending_value) > 0",
            ],
            [
                'code'     => 'order_open_fully_supplied',
                'severity' => 'warning',
                'title'    => 'Order open/partial but nothing left to supply (should be closed)',
                'sql'      => "SELECT o.id FROM pending_orders o JOIN pending_order_items i ON i.order_id = o.id
                               WHERE o.status IN ('open','partial') AND o.deleted_at IS NULL
                               GROUP BY o.id HAVING SUM(i.pending_value) = 0",
            ],
            [
                'code'     => 'order_without_items',
                'severity' => 'warning',
                'title'    => 'Pending order has no line items',
                'sql'      => 'SELECT o.id FROM pending_orders o
                               WHERE o.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM pending_order_items i WHERE i.order_id = o.id)',
            ],
            [
                'code'     => 'dc_invoiced_without_invoice',
                'severity' => 'warning',
                'title'    => 'DC marked invoiced but no invoice linked',
                'sql'      => "SELECT id FROM dc_records WHERE pending_status = 'invoiced' AND invoice_id IS NULL",
            ],
            [
                'code'     => 'fy_current_count',
                'severity' => 'error',
                'title'    => 'Exactly one financial year must be marked current',
                'sql'      => 'SELECT 0 AS id FROM financial_years HAVING SUM(is_current) <> 1',
            ],
            [
                'code'     => 'fy_overlap',
                'severity' => 'error',
                'title'    => 'Financial years overlap',
                'sql'      => 'SELECT a.id FROM financial_years a JOIN financial_years b
                               ON a.id < b.id AND a.start_date <= b.end_date AND b.start_date <= a.end_date',
            ],
            [
                'code'     => 'own_scope_user_without_employee',
                'severity' => 'error',
                'title'    => '"Own records" user not linked to an employee (would see nothing)',
                'sql'      => "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                               WHERE r.data_scope IN ('own','team') AND u.employee_id IS NULL AND u.deleted_at IS NULL",
            ],
            [
                'code'     => 'branch_scope_user_without_branch',
                'severity' => 'warning',
                'title'    => '"Branch" scope user has no branches assigned',
                'sql'      => "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                               WHERE r.data_scope = 'branch' AND u.deleted_at IS NULL
                                 AND NOT EXISTS (SELECT 1 FROM user_branches ub WHERE ub.user_id = u.id)",
            ],
            [
                'code'     => 'target_for_non_sales_rep',
                'severity' => 'warning',
                'title'    => 'Sales target set for an employee who is not a sales rep',
                'sql'      => 'SELECT t.id FROM sales_targets t JOIN employees e ON e.id = t.employee_id WHERE e.is_sales_rep = 0',
            ],
            [
                'code'     => 'email_lead_mismatch',
                'severity' => 'warning',
                'title'    => 'Email linked to a lead whose source email points elsewhere',
                'sql'      => 'SELECT m.id FROM email_messages m JOIN leads l ON l.id = m.lead_id
                               WHERE l.email_message_id IS NOT NULL AND l.email_message_id <> m.id',
            ],
        ];
    }

    /**
     * @return list<array{code: string, severity: string, title: string, count: int, sample_ids: list<int>}>
     */
    public function run(): array
    {
        $results = [];
        foreach (self::rules() as $rule) {
            $ids = array_map('intval', Database::query($rule['sql'])->fetchAll(\PDO::FETCH_COLUMN));
            $results[] = [
                'code'       => $rule['code'],
                'severity'   => $rule['severity'],
                'title'      => $rule['title'],
                'count'      => count($ids),
                'sample_ids' => array_slice($ids, 0, 10),
            ];
        }
        return $results;
    }
}
