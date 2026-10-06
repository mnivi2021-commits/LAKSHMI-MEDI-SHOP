<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Imports\Importer;

/** Delivery challans (goods out, pending until invoiced or returned). */
final class DcImporter extends Importer
{
    public function key(): string { return 'dc'; }
    public function label(): string { return 'Delivery challans (DC)'; }
    public function permission(): string { return 'dc.import'; }
    public function groupField(): ?string { return 'dc_no'; }

    public function fields(): array
    {
        return [
            'dc_no'         => ['label' => 'DC No', 'required' => true, 'format' => 'Text, max 40', 'aliases' => ['dc number', 'challan no', 'delivery challan no'], 'example' => ['DC-501']],
            'dc_date'       => ['label' => 'DC Date', 'required' => true, 'format' => 'DD-MM-YYYY', 'aliases' => ['date', 'challan date'], 'example' => ['14-05-2026']],
            'customer_code' => ['label' => 'Customer Code', 'required' => true, 'format' => 'Existing customer code', 'aliases' => ['customer', 'party code'], 'example' => ['CUS-00002']],
            'product_code'  => ['label' => 'Product Code', 'required' => true, 'format' => 'Existing product code', 'aliases' => ['product', 'item code'], 'example' => ['P004']],
            'quantity'      => ['label' => 'Quantity', 'required' => true, 'format' => 'Number', 'aliases' => ['qty'], 'example' => ['10']],
            'dc_value'      => ['label' => 'DC Value', 'format' => 'Amount, default 0', 'aliases' => ['value', 'amount'], 'example' => ['9800']],
            'sample_no'     => ['label' => 'Sample No', 'format' => 'Optional: sample this DC supplied', 'aliases' => ['sample', 'sample number'], 'example' => ['']],
            'employee'      => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'rep'], 'example' => ['']],
            'remarks'       => ['label' => 'Remarks', 'format' => 'Text', 'aliases' => ['notes'], 'example' => ['']],
        ];
    }

    public function headerFields(): array
    {
        return ['dc_date', 'customer_id', 'employee_id', 'sample_id'];
    }

    public function notes(): array
    {
        return ['One row per product; rows with the same DC No form one DC.', 'Imported DCs are "pending" until invoiced or returned.'];
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $no = $this->docNo($in, 'dc_no');
        $date = $this->date($in, 'dc_date', true);
        $fy = $this->fy($date, 'dc_date');
        $customer = $this->customer($in);
        $employee = $this->employee($in, 'employee', $customer);
        $product = $this->product($in, 'product_code', true);
        $qty = $this->qty($in, 'quantity', true);
        $value = $this->money($in, 'dc_value', false, true) ?? 0;
        $remarks = $this->text($in, 'remarks', 255);
        $sampleId = null;
        $sampleNo = (string) ($in['sample_no'] ?? '');
        if ($sampleNo !== '' && $customer !== null) {
            $sampleId = Database::value('SELECT id FROM samples WHERE document_no = ? AND customer_id = ? AND deleted_at IS NULL ORDER BY document_date DESC LIMIT 1',
                [$sampleNo, $customer['id']]);
            if (!$sampleId) {
                $this->errors[] = "Sample {$sampleNo} was not found for customer {$customer['customer_code']}.";
            }
        }
        if ($this->errors) {
            return $this->done([]);
        }
        return $this->done([
            'fy_id' => (int) $fy['id'], 'fy_label' => $fy['label'], 'dc_no' => $no, 'dc_date' => $date,
            'customer_id' => (int) $customer['id'], 'branch_id' => (int) $customer['branch_id'], 'employee_id' => $employee,
            'sample_id' => $sampleId ? (int) $sampleId : null, 'product_id' => (int) $product['id'], 'quantity' => $qty, 'value' => $value, 'remarks' => $remarks,
        ]);
    }

    public function validateDocument(array $lines): array
    {
        $ids = array_column($lines, 'product_id');
        return count($ids) !== count(array_unique($ids)) ? ['The same product appears twice in this DC; combine the lines.'] : [];
    }

    public function existing(array $data): ?string
    {
        return Database::value('SELECT 1 FROM dc_records WHERE financial_year_id = ? AND dc_no = ?', [$data['fy_id'], $data['dc_no']])
            ? "DC {$data['dc_no']} already exists in {$data['fy_label']}." : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $h = $lines[0];
        $remarks = implode('; ', array_unique(array_filter(array_column($lines, 'remarks')))) ?: null;
        Database::query(
            "INSERT INTO dc_records (financial_year_id, dc_no, dc_date, sample_id, customer_id, branch_id, employee_id, supply_status, pending_status,
                                     import_batch_id, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'supplied', 'pending', ?, ?, ?, ?)",
            [$h['fy_id'], $h['dc_no'], $h['dc_date'], $h['sample_id'], $h['customer_id'], $h['branch_id'], $h['employee_id'],
             $batchId, $remarks !== null ? mb_substr($remarks, 0, 500) : null, $this->uid(), $this->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare('INSERT INTO dc_items (dc_id, product_id, quantity, dc_value) VALUES (?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['quantity'], Money::toDecimal($l['value'])]);
        }
        return ['dc_records', $id];
    }
}
