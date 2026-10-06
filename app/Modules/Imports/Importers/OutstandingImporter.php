<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Imports\Importer;

/**
 * Bill-wise outstanding statement from the accounting system (a snapshot "as on" a date).
 * Used by the dashboard when Settings > Outstanding source = Imported.
 */
final class OutstandingImporter extends Importer
{
    public function key(): string { return 'outstanding'; }
    public function label(): string { return 'Outstanding statement'; }
    public function permission(): string { return 'outstanding.import'; }

    public function fields(): array
    {
        return [
            'as_on_date'     => ['label' => 'As On Date', 'required' => true, 'format' => 'DD-MM-YYYY (statement date)', 'aliases' => ['as on', 'statement date', 'report date'], 'example' => ['30-09-2026']],
            'customer_code'  => ['label' => 'Customer Code', 'required' => true, 'format' => 'Existing customer code', 'aliases' => ['customer', 'party code'], 'example' => ['CUS-00005']],
            'invoice_no'     => ['label' => 'Invoice No', 'required' => true, 'format' => 'Text, max 40', 'aliases' => ['bill no', 'ref no', 'invoice number'], 'example' => ['INV-1450']],
            'invoice_date'   => ['label' => 'Invoice Date', 'required' => true, 'format' => 'DD-MM-YYYY', 'aliases' => ['bill date', 'date'], 'example' => ['15-04-2026']],
            'due_date'       => ['label' => 'Due Date', 'format' => 'DD-MM-YYYY (blank = credit days)', 'aliases' => ['due on'], 'example' => ['15-05-2026']],
            'bill_amount'    => ['label' => 'Bill Amount', 'required' => true, 'format' => 'Amount', 'aliases' => ['invoice amount', 'bill value'], 'example' => ['1,18,000']],
            'pending_amount' => ['label' => 'Pending Amount', 'required' => true, 'format' => 'Amount still unpaid', 'aliases' => ['pending', 'balance', 'outstanding', 'due amount'], 'example' => ['68,000']],
            'employee'       => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'rep'], 'example' => ['']],
        ];
    }

    public function notes(): array
    {
        return [
            'Upload the full statement for one date. The dashboard always uses the latest As On Date.',
            'The dashboard uses imported statements only when Settings > Outstanding source is set to "Imported"; otherwise it computes outstanding from invoices and receipts.',
            'The same bill (As On Date + Customer + Invoice No) is skipped if already imported.',
        ];
    }

    public function recordKey(array $data): ?string
    {
        return $data['as_on_date'] . '|' . $data['customer_id'] . '|' . strtoupper($data['invoice_no']);
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $asOn = $this->date($in, 'as_on_date', true);
        $customer = $this->customer($in);
        $employee = $this->employee($in, 'employee', $customer);
        $no = $this->docNo($in, 'invoice_no');
        $date = $this->date($in, 'invoice_date', true);
        $due = $this->date($in, 'due_date', false, false);
        $bill = $this->money($in, 'bill_amount', true);
        $pending = $this->money($in, 'pending_amount', true);
        if ($bill !== null && $pending !== null && $pending > $bill) {
            $this->errors[] = 'Pending Amount cannot be more than the Bill Amount.';
        }
        if ($date !== null && $asOn !== null && $date > $asOn) {
            $this->errors[] = 'Invoice Date is after the As On Date.';
        }
        if ($this->errors) {
            return $this->done([]);
        }
        return $this->done([
            'as_on_date' => $asOn, 'customer_id' => (int) $customer['id'], 'branch_id' => (int) $customer['branch_id'], 'employee_id' => $employee,
            'invoice_no' => $no, 'invoice_date' => $date, 'due_date' => $due, 'bill_amount' => $bill, 'pending_amount' => $pending,
        ]);
    }

    public function existing(array $data): ?string
    {
        return Database::value('SELECT 1 FROM outstanding_bills WHERE as_on_date = ? AND customer_id = ? AND invoice_no = ?',
            [$data['as_on_date'], $data['customer_id'], $data['invoice_no']]) ? "Bill {$data['invoice_no']} is already in the statement as on " . date('d-m-Y', strtotime($data['as_on_date'])) . '.' : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $d = $lines[0];
        Database::query(
            'INSERT INTO outstanding_bills (as_on_date, customer_id, branch_id, employee_id, invoice_no, invoice_date, due_date, bill_amount, pending_amount, import_batch_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['as_on_date'], $d['customer_id'], $d['branch_id'], $d['employee_id'], $d['invoice_no'], $d['invoice_date'], $d['due_date'],
             Money::toDecimal($d['bill_amount']), Money::toDecimal($d['pending_amount']), $batchId, $this->uid()]
        );
        return ['outstanding_bills', (int) Database::connection()->lastInsertId()];
    }
}
