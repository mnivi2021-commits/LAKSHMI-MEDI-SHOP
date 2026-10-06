<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Imports\Importer;

/**
 * Payments received. Each receipt is adjusted against the named invoice, or
 * (when none is named) against the customer's oldest open bills - the same rule
 * as the dashboard quick-add, so outstanding stays correct.
 */
final class CollectionImporter extends Importer
{
    private const MODES = ['neft' => 'neft', 'rtgs' => 'rtgs', 'imps' => 'imps', 'upi' => 'upi', 'gpay' => 'upi', 'phonepe' => 'upi',
        'cheque' => 'cheque', 'check' => 'cheque', 'chq' => 'cheque', 'cash' => 'cash', 'dd' => 'dd', 'demanddraft' => 'dd',
        'banktransfer' => 'neft', 'online' => 'neft', 'other' => 'other', 'others' => 'other'];
    private const STATUSES = ['cleared' => 'cleared', 'received' => 'received', 'pending' => 'received', 'bounced' => 'bounced',
        'returned' => 'bounced', 'cancelled' => 'cancelled', 'canceled' => 'cancelled'];

    public function key(): string { return 'collections'; }
    public function label(): string { return 'Payments (collections)'; }
    public function permission(): string { return 'collections.import'; }

    public function fields(): array
    {
        return [
            'receipt_no'    => ['label' => 'Receipt No', 'required' => true, 'format' => 'Text, max 40', 'aliases' => ['receipt number', 'voucher no', 'payment no'], 'example' => ['RC-7001']],
            'receipt_date'  => ['label' => 'Receipt Date', 'required' => true, 'format' => 'DD-MM-YYYY', 'aliases' => ['date', 'payment date'], 'example' => ['02-06-2026']],
            'customer_code' => ['label' => 'Customer Code', 'required' => true, 'format' => 'Existing customer code', 'aliases' => ['customer', 'party code'], 'example' => ['CUS-00004']],
            'amount'        => ['label' => 'Amount', 'required' => true, 'format' => 'Amount', 'aliases' => ['received amount', 'payment amount'], 'example' => ['45,000']],
            'payment_mode'  => ['label' => 'Payment Mode', 'required' => true, 'format' => 'NEFT / RTGS / IMPS / UPI / Cheque / Cash / DD / Other', 'aliases' => ['mode'], 'example' => ['NEFT']],
            'reference_no'  => ['label' => 'Reference No', 'format' => 'UTR / cheque number', 'aliases' => ['utr', 'cheque no', 'ref no'], 'example' => ['UTR12345']],
            'bank_name'     => ['label' => 'Bank', 'format' => 'Text', 'aliases' => ['bank name'], 'example' => ['HDFC']],
            'status'        => ['label' => 'Status', 'format' => 'Cleared / Received / Bounced / Cancelled (default: Received for cheque/DD, else Cleared)', 'aliases' => ['payment status'], 'example' => ['']],
            'invoice_no'    => ['label' => 'Against Invoice', 'format' => 'Optional invoice no; blank = oldest bills first', 'aliases' => ['invoice no', 'bill no'], 'example' => ['']],
            'employee'      => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'rep'], 'example' => ['']],
            'remarks'       => ['label' => 'Remarks', 'format' => 'Text', 'aliases' => ['notes', 'narration'], 'example' => ['']],
        ];
    }

    public function notes(): array
    {
        return [
            'Bounced and cancelled receipts are imported for the record but never count as collection.',
            'A receipt number that already exists in the same financial year is skipped as a duplicate.',
            'Any amount not adjusted against bills is kept "on account" for the customer.',
        ];
    }

    public function recordKey(array $data): ?string
    {
        return $data['fy_id'] . '|' . strtoupper($data['receipt_no']);
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $no = $this->docNo($in, 'receipt_no');
        $date = $this->date($in, 'receipt_date', true);
        $fy = $this->fy($date, 'receipt_date');
        $customer = $this->customer($in);
        $employee = $this->employee($in, 'employee', $customer);
        $amount = $this->money($in, 'amount', true);
        $mode = ($in['payment_mode'] ?? '') === '' ? null : $this->choice($in, 'payment_mode', self::MODES, null);
        if (($in['payment_mode'] ?? '') === '') {
            $this->errors[] = 'Payment Mode is required.';
        }
        $status = $this->choice($in, 'status', self::STATUSES, null);
        $reference = $this->text($in, 'reference_no', 60);
        $bank = $this->text($in, 'bank_name', 100);
        $remarks = $this->text($in, 'remarks', 500);

        $invoiceId = null;
        $inv = (string) ($in['invoice_no'] ?? '');
        if ($inv !== '' && $customer !== null) {
            $invoiceId = Database::value("SELECT id FROM sales_invoices WHERE invoice_no = ? AND document_type = 'invoice' AND customer_id = ?
                                          AND status = 'active' AND deleted_at IS NULL ORDER BY invoice_date DESC LIMIT 1", [$inv, $customer['id']]);
            if (!$invoiceId) {
                $this->errors[] = "Invoice {$inv} was not found for customer {$customer['customer_code']}.";
            }
        }
        if ($this->errors) {
            return $this->done([]);
        }
        $status ??= in_array($mode, ['cheque', 'dd'], true) ? 'received' : 'cleared';
        return $this->done([
            'fy_id' => (int) $fy['id'], 'fy_label' => $fy['label'], 'receipt_no' => $no, 'receipt_date' => $date,
            'customer_id' => (int) $customer['id'], 'branch_id' => (int) $customer['branch_id'], 'employee_id' => $employee,
            'amount' => $amount, 'payment_mode' => $mode, 'status' => $status, 'reference_no' => $reference, 'bank_name' => $bank,
            'invoice_id' => $invoiceId ? (int) $invoiceId : null, 'remarks' => $remarks,
        ]);
    }

    public function existing(array $data): ?string
    {
        return Database::value('SELECT 1 FROM collections WHERE financial_year_id = ? AND receipt_no = ?', [$data['fy_id'], $data['receipt_no']])
            ? "Receipt {$data['receipt_no']} already exists in {$data['fy_label']}." : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $d = $lines[0];
        Database::query(
            "INSERT INTO collections (financial_year_id, receipt_no, receipt_date, customer_id, branch_id, employee_id, amount, payment_mode,
                                      reference_no, bank_name, status, source, import_batch_id, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?, ?, ?)",
            [$d['fy_id'], $d['receipt_no'], $d['receipt_date'], $d['customer_id'], $d['branch_id'], $d['employee_id'], Money::toDecimal($d['amount']),
             $d['payment_mode'], $d['reference_no'], $d['bank_name'], $d['status'], $batchId, $d['remarks'], $this->uid(), $this->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();

        if (in_array($d['status'], ['received', 'cleared'], true)) {
            Database::query('SELECT id FROM sales_invoices WHERE customer_id = ? FOR UPDATE', [$d['customer_id']]);
            $open = $d['invoice_id'] !== null
                ? Database::fetchAll('SELECT invoice_id, balance FROM v_invoice_balances WHERE invoice_id = ? AND balance > 0', [$d['invoice_id']])
                : Database::fetchAll('SELECT invoice_id, balance FROM v_invoice_balances WHERE customer_id = ? AND balance > 0 ORDER BY invoice_date, invoice_id', [$d['customer_id']]);
            $left = $d['amount'];
            $ins = Database::connection()->prepare('INSERT INTO collection_allocations (collection_id, invoice_id, amount, created_by) VALUES (?, ?, ?, ?)');
            foreach ($open as $bill) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, Money::fromDb($bill['balance']));
                if ($take > 0) {
                    $ins->execute([$id, $bill['invoice_id'], Money::toDecimal($take), $this->uid()]);
                    $left -= $take;
                }
            }
        }
        return ['collections', $id];
    }
}
