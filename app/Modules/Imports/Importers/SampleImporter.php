<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Imports\Importer;

/** Samples given to customers (pending until the customer's outcome is recorded). */
final class SampleImporter extends Importer
{
    private const SUPPLY = ['notsupplied' => 'not_supplied', 'no' => 'not_supplied', 'pending' => 'not_supplied',
        'partiallysupplied' => 'partially_supplied', 'partial' => 'partially_supplied', 'supplied' => 'supplied', 'yes' => 'supplied'];

    public function key(): string { return 'samples'; }
    public function label(): string { return 'Samples'; }
    public function permission(): string { return 'samples.import'; }
    public function groupField(): ?string { return 'document_no'; }

    public function fields(): array
    {
        return [
            'document_no'   => ['label' => 'Sample No', 'required' => true, 'format' => 'Text, max 40', 'aliases' => ['document no', 'sample number', 'doc no'], 'example' => ['SMP-301']],
            'document_date' => ['label' => 'Sample Date', 'required' => true, 'format' => 'DD-MM-YYYY', 'aliases' => ['date', 'document date'], 'example' => ['12-05-2026']],
            'customer_code' => ['label' => 'Customer Code', 'required' => true, 'format' => 'Existing customer code', 'aliases' => ['customer', 'party code'], 'example' => ['CUS-00002']],
            'product_code'  => ['label' => 'Product Code', 'required' => true, 'format' => 'Existing product code', 'aliases' => ['product', 'item code'], 'example' => ['P004']],
            'quantity'      => ['label' => 'Quantity', 'required' => true, 'format' => 'Number', 'aliases' => ['qty'], 'example' => ['2']],
            'sample_value'  => ['label' => 'Sample Value', 'format' => 'Amount, default 0', 'aliases' => ['value', 'amount'], 'example' => ['1960']],
            'supply_status' => ['label' => 'Supply Status', 'format' => 'Not supplied / Partially supplied / Supplied', 'aliases' => ['status'], 'example' => ['Supplied']],
            'employee'      => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'rep'], 'example' => ['']],
            'remarks'       => ['label' => 'Remarks', 'format' => 'Text', 'aliases' => ['notes'], 'example' => ['Trial for new line']],
        ];
    }

    public function headerFields(): array
    {
        return ['document_date', 'customer_id', 'employee_id', 'supply_status'];
    }

    public function notes(): array
    {
        return ['One row per product; rows with the same Sample No form one sample.', 'Imported samples are "pending" until the customer outcome is recorded.'];
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $no = $this->docNo($in, 'document_no');
        $date = $this->date($in, 'document_date', true);
        $fy = $this->fy($date, 'document_date');
        $customer = $this->customer($in);
        $employee = $this->employee($in, 'employee', $customer);
        $product = $this->product($in, 'product_code', true);
        $qty = $this->qty($in, 'quantity', true);
        $value = $this->money($in, 'sample_value', false, true) ?? 0;
        $supply = $this->choice($in, 'supply_status', self::SUPPLY, 'not_supplied');
        $remarks = $this->text($in, 'remarks', 255);
        if ($this->errors) {
            return $this->done([]);
        }
        return $this->done([
            'fy_id' => (int) $fy['id'], 'fy_label' => $fy['label'], 'document_no' => $no, 'document_date' => $date,
            'customer_id' => (int) $customer['id'], 'branch_id' => (int) $customer['branch_id'], 'employee_id' => $employee,
            'product_id' => (int) $product['id'], 'quantity' => $qty, 'value' => $value, 'supply_status' => $supply, 'remarks' => $remarks,
        ]);
    }

    public function validateDocument(array $lines): array
    {
        $ids = array_column($lines, 'product_id');
        return count($ids) !== count(array_unique($ids)) ? ['The same product appears twice in this sample; combine the lines.'] : [];
    }

    public function existing(array $data): ?string
    {
        return Database::value('SELECT 1 FROM samples WHERE financial_year_id = ? AND document_no = ?', [$data['fy_id'], $data['document_no']])
            ? "Sample {$data['document_no']} already exists in {$data['fy_label']}." : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $h = $lines[0];
        $remarks = implode('; ', array_unique(array_filter(array_column($lines, 'remarks')))) ?: null;
        Database::query(
            "INSERT INTO samples (financial_year_id, document_no, document_date, customer_id, branch_id, employee_id, supply_status, pending_status,
                                  import_batch_id, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)",
            [$h['fy_id'], $h['document_no'], $h['document_date'], $h['customer_id'], $h['branch_id'], $h['employee_id'], $h['supply_status'],
             $batchId, $remarks !== null ? mb_substr($remarks, 0, 500) : null, $this->uid(), $this->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare('INSERT INTO sample_items (sample_id, product_id, quantity, sample_value) VALUES (?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['quantity'], Money::toDecimal($l['value'])]);
        }
        return ['samples', $id];
    }
}
