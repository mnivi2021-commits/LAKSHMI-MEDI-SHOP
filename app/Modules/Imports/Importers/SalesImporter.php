<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Imports\Importer;
use DateTimeImmutable;

/**
 * Sales invoices and credit notes. Either one row per product line (Product Code
 * filled - counts in product-wise figures too) or one row per document without
 * product (counts in sales totals only).
 */
final class SalesImporter extends Importer
{
    private const TYPES = ['invoice' => 'invoice', 'inv' => 'invoice', 'salesinvoice' => 'invoice', 'taxinvoice' => 'invoice', 'sales' => 'invoice',
        'creditnote' => 'credit_note', 'cn' => 'credit_note', 'credit' => 'credit_note', 'salesreturn' => 'credit_note', 'return' => 'credit_note'];

    public function key(): string { return 'sales'; }
    public function label(): string { return 'Sales invoices'; }
    public function permission(): string { return 'sales.import'; }
    public function groupField(): ?string { return 'invoice_no'; }

    public function fields(): array
    {
        return [
            'invoice_no'     => ['label' => 'Invoice No', 'required' => true, 'format' => 'Text, max 40', 'aliases' => ['bill no', 'invoice number', 'voucher no', 'document no'], 'example' => ['INV-2001', 'INV-2001']],
            'customer_po_no' => ['label' => 'Customer PO No', 'format' => 'Optional, max 60', 'aliases' => ['po no', 'po number', 'po ref', 'p.o reference', 'customer po', 'order ref'], 'example' => ['PO/7781', 'PO/7781']],
            'invoice_date'   => ['label' => 'Invoice Date', 'required' => true, 'format' => 'DD-MM-YYYY', 'aliases' => ['date', 'bill date'], 'example' => ['20-05-2026', '20-05-2026']],
            'document_type'  => ['label' => 'Type', 'format' => 'Invoice / Credit note (default Invoice)', 'aliases' => ['document type', 'voucher type'], 'example' => ['Invoice', 'Invoice']],
            'customer_code'  => ['label' => 'Customer Code', 'required' => true, 'format' => 'Existing customer code', 'aliases' => ['customer', 'party code'], 'example' => ['CUS-00003', 'CUS-00003']],
            'product_code'   => ['label' => 'Product Code', 'format' => 'Optional (needed for product-wise sales)', 'aliases' => ['product', 'item code'], 'example' => ['P002', 'P007']],
            'quantity'       => ['label' => 'Quantity', 'format' => 'Number (with Product Code)', 'aliases' => ['qty'], 'example' => ['10', '5']],
            'rate'           => ['label' => 'Rate', 'format' => 'Amount per unit (or give Taxable Value)', 'aliases' => ['price'], 'example' => ['1150', '']],
            'taxable_amount' => ['label' => 'Taxable Value', 'format' => 'Amount before GST', 'aliases' => ['taxable', 'taxable amount', 'basic amount', 'net amount'], 'example' => ['', '3600']],
            'gst_rate'       => ['label' => 'GST %', 'format' => 'e.g. 18 (blank = product GST)', 'aliases' => ['gst', 'gst rate', 'tax rate'], 'example' => ['18', '18']],
            'tax_amount'     => ['label' => 'Tax Amount', 'format' => 'Optional; blank = Taxable x GST %', 'aliases' => ['gst amount', 'tax'], 'example' => ['', '']],
            'total_amount'   => ['label' => 'Total Amount', 'format' => 'Optional check (Taxable + Tax, within ₹1)', 'aliases' => ['total', 'invoice value', 'gross amount'], 'example' => ['', '']],
            'due_date'       => ['label' => 'Due Date', 'format' => 'DD-MM-YYYY (blank = credit days)', 'aliases' => ['due'], 'example' => ['', '']],
            'reference_no'   => ['label' => 'Against Invoice', 'format' => 'Credit notes: original invoice no', 'aliases' => ['reference invoice', 'original invoice'], 'example' => ['', '']],
            'employee'       => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'rep'], 'example' => ['', '']],
            'remarks'        => ['label' => 'Remarks', 'format' => 'Text', 'aliases' => ['notes', 'narration'], 'example' => ['', '']],
        ];
    }

    public function headerFields(): array
    {
        return ['invoice_date', 'customer_id', 'employee_id', 'due_date', 'reference_invoice_id'];
    }

    public function documentKey(array $data): ?string
    {
        return $data['fy_id'] . '|' . $data['document_type'] . '|' . strtoupper($data['invoice_no']);
    }

    public function notes(): array
    {
        return [
            'Sales are counted on the Taxable Value (settings can switch to totals incl. GST).',
            'Credit notes are entered as positive amounts with Type = Credit note; they reduce sales and the original invoice balance.',
            'Rows with the same Invoice No (and Type) form one invoice. Without Product Code, give one row per invoice.',
            'An invoice number that already exists in the same financial year is skipped as a duplicate.',
        ];
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $no = $this->docNo($in, 'invoice_no');
        $date = $this->date($in, 'invoice_date', true);
        $fy = $this->fy($date, 'invoice_date');
        $type = $this->choice($in, 'document_type', self::TYPES, 'invoice');
        $customer = $this->customer($in);
        $employee = $this->employee($in, 'employee', $customer);
        $product = $this->product($in, 'product_code', false);
        $qty = $this->qty($in, 'quantity', $product !== null);
        $rate = $this->money($in, 'rate', false, true);
        $taxable = $this->money($in, 'taxable_amount', false);
        $taxGiven = $this->money($in, 'tax_amount', false, true);
        $totalGiven = $this->money($in, 'total_amount', false);
        $due = $this->date($in, 'due_date', false, false);
        $remarks = $this->text($in, 'remarks', 255);
        $poNo = $this->text($in, 'customer_po_no', 60);

        if (($in['product_code'] ?? '') === '' && ($in['quantity'] ?? '') !== '') {
            $this->errors[] = 'Quantity was given without a Product Code.';
        }
        if ($taxable === null && ($in['taxable_amount'] ?? '') === '') {
            if ($rate !== null && $qty !== null) {
                $taxable = Money::times($rate, $qty);
            } else {
                $this->errors[] = 'Give the Taxable Value (or Rate and Quantity).';
            }
        }
        $gst = (string) ($in['gst_rate'] ?? '');
        $gst = rtrim(str_replace('%', '', $gst));
        if ($gst === '' && $product !== null) {
            $gst = rtrim(rtrim((string) $product['gst_rate'], '0'), '.') ?: '0';
        }
        if ($gst === '' && $taxGiven === null) {
            $this->errors[] = 'Give GST % or Tax Amount (no product to take the GST rate from).';
        } elseif ($gst !== '' && (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $gst))) {
            $this->errors[] = "GST % \"{$gst}\" must be a number like 18 or 12.5.";
            $gst = '';
        }
        $tax = $taxGiven ?? ($taxable !== null && $gst !== '' ? Money::percentOf($taxable, $gst) : null);
        $roundOff = 0;
        if ($taxable !== null && $tax !== null && $totalGiven !== null) {
            $roundOff = $totalGiven - ($taxable + $tax);
            if (abs($roundOff) > 100) {
                $this->errors[] = 'Total Amount ' . Money::toDecimal($totalGiven) . ' does not equal Taxable + Tax (' . Money::toDecimal($taxable + $tax) . ').';
            }
        }
        if ($due !== null && $date !== null && $due < $date) {
            $this->errors[] = 'Due Date cannot be before the Invoice Date.';
        }

        $refId = null;
        $ref = (string) ($in['reference_no'] ?? '');
        if ($ref !== '' && $type === 'credit_note' && $customer !== null) {
            $refId = Database::value("SELECT id FROM sales_invoices WHERE invoice_no = ? AND document_type = 'invoice' AND customer_id = ? AND deleted_at IS NULL
                                      ORDER BY invoice_date DESC LIMIT 1", [$ref, $customer['id']]);
            if (!$refId) {
                $this->errors[] = "Original invoice {$ref} was not found for customer {$customer['customer_code']}.";
            }
        } elseif ($ref !== '' && $type === 'invoice') {
            $this->errors[] = 'Against Invoice is only for credit notes.';
        }

        if ($this->errors) {
            return $this->done([]);
        }
        $due ??= $type === 'invoice' ? (new DateTimeImmutable($date))->modify('+' . (int) $customer['credit_days'] . ' days')->format('Y-m-d') : null;
        return $this->done([
            'fy_id' => (int) $fy['id'], 'fy_label' => $fy['label'], 'invoice_no' => $no, 'invoice_date' => $date, 'document_type' => $type,
            'customer_id' => (int) $customer['id'], 'branch_id' => (int) $customer['branch_id'], 'employee_id' => $employee,
            'product_id' => $product !== null ? (int) $product['id'] : null, 'quantity' => $qty, 'taxable' => $taxable, 'tax' => $tax,
            'gst_rate' => $gst !== '' ? $gst : '0', 'round_off' => $roundOff, 'due_date' => $due, 'reference_invoice_id' => $refId ? (int) $refId : null, 'remarks' => $remarks, 'customer_po_no' => $poNo,
        ]);
    }

    public function validateDocument(array $lines): array
    {
        $withProduct = array_filter($lines, static fn ($l) => $l['product_id'] !== null);
        if (count($lines) > 1 && count($withProduct) !== count($lines)) {
            return ['An invoice with several rows needs a Product Code on every row (or give one row per invoice).'];
        }
        $ids = array_column($withProduct, 'product_id');
        return count($ids) !== count(array_unique($ids)) ? ['The same product appears twice in this invoice; combine the lines.'] : [];
    }

    public function existing(array $data): ?string
    {
        $what = $data['document_type'] === 'credit_note' ? 'Credit note' : 'Invoice';
        return Database::value('SELECT 1 FROM sales_invoices WHERE financial_year_id = ? AND document_type = ? AND invoice_no = ?',
            [$data['fy_id'], $data['document_type'], $data['invoice_no']]) ? "{$what} {$data['invoice_no']} already exists in {$data['fy_label']}." : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $h = $lines[0];
        $taxable = array_sum(array_column($lines, 'taxable'));
        $tax = array_sum(array_column($lines, 'tax'));
        $round = array_sum(array_column($lines, 'round_off'));
        $remarks = implode('; ', array_unique(array_filter(array_column($lines, 'remarks')))) ?: null;
        Database::query(
            "INSERT INTO sales_invoices (financial_year_id, document_type, invoice_no, customer_po_no, invoice_date, due_date, customer_id, branch_id, employee_id,
                                         reference_invoice_id, taxable_amount, tax_amount, round_off, total_amount, source, import_batch_id, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?, ?, ?)",
            [$h['fy_id'], $h['document_type'], $h['invoice_no'], $h['customer_po_no'] ?? null, $h['invoice_date'], $h['due_date'], $h['customer_id'], $h['branch_id'], $h['employee_id'],
             $h['reference_invoice_id'], Money::toDecimal($taxable), Money::toDecimal($tax), Money::toDecimal($round), Money::toDecimal($taxable + $tax + $round),
             $batchId, $remarks !== null ? mb_substr($remarks, 0, 500) : null, $this->uid(), $this->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare(
            'INSERT INTO sales_invoice_items (invoice_id, product_id, quantity, rate, taxable_amount, gst_rate, tax_amount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            if ($l['product_id'] === null) {
                continue;
            }
            $ins->execute([$id, $l['product_id'], $l['quantity'], Money::toDecimal(Money::perUnit($l['taxable'], $l['quantity'])),
                Money::toDecimal($l['taxable']), $l['gst_rate'], Money::toDecimal($l['tax']), Money::toDecimal($l['taxable'] + $l['tax'])]);
        }
        return ['sales_invoices', $id];
    }
}
