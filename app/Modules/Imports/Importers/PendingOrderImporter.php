<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Imports\Importer;

/** Pending orders: one row per product line; rows with the same Order No form one order. */
final class PendingOrderImporter extends Importer
{
    public function key(): string { return 'pending_orders'; }
    public function label(): string { return 'Pending orders'; }
    public function permission(): string { return 'pending_orders.import'; }
    public function groupField(): ?string { return 'order_no'; }

    public function fields(): array
    {
        return [
            'order_no'       => ['label' => 'Order No', 'required' => true, 'format' => 'Text, max 40', 'aliases' => ['order number', 'so no', 'sales order no', 'order'], 'example' => ['SO-1001', 'SO-1001']],
            'order_date'     => ['label' => 'Order Date', 'required' => true, 'format' => 'DD-MM-YYYY', 'aliases' => ['date', 'so date'], 'example' => ['05-04-2026', '05-04-2026']],
            'customer_code'  => ['label' => 'Customer Code', 'required' => true, 'format' => 'Existing customer code', 'aliases' => ['customer', 'party code', 'customer id'], 'example' => ['CUS-00001', 'CUS-00001']],
            'product_code'   => ['label' => 'Product Code', 'required' => true, 'format' => 'Existing product code', 'aliases' => ['product', 'item code', 'item'], 'example' => ['P001', 'P003']],
            'order_qty'      => ['label' => 'Order Qty', 'required' => true, 'format' => 'Number, up to 3 decimals', 'aliases' => ['qty', 'quantity', 'order quantity'], 'example' => ['500', '200']],
            'rate'           => ['label' => 'Rate', 'format' => 'Amount per unit (or give Order Value)', 'aliases' => ['price', 'unit rate'], 'example' => ['42', '']],
            'order_value'    => ['label' => 'Order Value', 'format' => 'Amount (or give Rate)', 'aliases' => ['value', 'amount', 'line value'], 'example' => ['', '7600']],
            'supplied_qty'   => ['label' => 'Supplied Qty', 'format' => 'Number, default 0', 'aliases' => ['dispatched qty', 'supplied quantity', 'delivered qty'], 'example' => ['100', '']],
            'customer_po_no' => ['label' => 'Customer PO No', 'format' => 'Text, max 60', 'aliases' => ['po no', 'po number', 'customer po'], 'example' => ['PO/889', 'PO/889']],
            'expected_date'  => ['label' => 'Expected Delivery', 'format' => 'DD-MM-YYYY', 'aliases' => ['delivery date', 'expected delivery date'], 'example' => ['30-04-2026', '30-04-2026']],
            'employee'       => ['label' => 'Sales Employee', 'format' => 'Employee code or short name; blank = customer\'s employee', 'aliases' => ['employee code', 'sales person', 'rep'], 'example' => ['', '']],
            'remarks'        => ['label' => 'Remarks', 'format' => 'Text, max 255', 'aliases' => ['notes'], 'example' => ['', '']],
        ];
    }

    public function headerFields(): array
    {
        return ['order_date', 'customer_id', 'employee_id', 'customer_po_no', 'expected_date'];
    }

    public function notes(): array
    {
        return [
            'One row per product. Rows with the same Order No become one order with several lines.',
            'Pending value = Order Value - value of the Supplied Qty. Fully supplied orders are saved as closed and do not count as pending.',
            'An order number that already exists in the same financial year is skipped as a duplicate.',
        ];
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $no = $this->docNo($in, 'order_no');
        $date = $this->date($in, 'order_date', true);
        $fy = $this->fy($date, 'order_date');
        $customer = $this->customer($in);
        $employee = $this->employee($in, 'employee', $customer);
        $product = $this->product($in, 'product_code', true);
        $qty = $this->qty($in, 'order_qty', true);
        $rate = $this->money($in, 'rate', false, true);
        $value = $this->money($in, 'order_value', false);
        $supplied = $this->qty($in, 'supplied_qty', false, true) ?? '0';
        $delivery = $this->date($in, 'expected_date', false, false);
        $po = $this->text($in, 'customer_po_no', 60);
        $remarks = $this->text($in, 'remarks', 255);

        if ($value === null && $rate === null && ($in['order_value'] ?? '') === '' && ($in['rate'] ?? '') === '') {
            $this->errors[] = 'Give either Rate or Order Value.';
        }
        if ($qty !== null && $value === null && $rate !== null) {
            $value = Money::times($rate, $qty);
            if ($value === 0) {
                $this->errors[] = 'Order value works out to zero.';
            }
        }
        if ($qty !== null && (float) $supplied > (float) $qty) {
            $this->errors[] = 'Supplied Qty cannot be more than Order Qty.';
        }
        if ($delivery !== null && $date !== null && $delivery < $date) {
            $this->errors[] = 'Expected Delivery cannot be before the Order Date.';
        }
        if ($this->errors) {
            return $this->done([]);
        }
        return $this->done([
            'fy_id' => (int) $fy['id'], 'fy_label' => $fy['label'], 'order_no' => $no, 'order_date' => $date,
            'customer_id' => (int) $customer['id'], 'branch_id' => (int) $customer['branch_id'], 'employee_id' => $employee,
            'product_id' => (int) $product['id'], 'order_qty' => $qty, 'supplied_qty' => $supplied,
            'order_value' => $value, 'supplied_value' => self::share($value, $supplied, $qty),
            'customer_po_no' => $po, 'expected_date' => $delivery, 'remarks' => $remarks,
        ]);
    }

    public function validateDocument(array $lines): array
    {
        $ids = array_column($lines, 'product_id');
        return count($ids) !== count(array_unique($ids)) ? ['The same product appears twice in this order; combine the lines.'] : [];
    }

    public function existing(array $data): ?string
    {
        return Database::value('SELECT 1 FROM pending_orders WHERE financial_year_id = ? AND order_no = ?', [$data['fy_id'], $data['order_no']])
            ? "Order {$data['order_no']} already exists in {$data['fy_label']}." : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $h = $lines[0];
        $value = array_sum(array_column($lines, 'order_value'));
        $supplied = array_sum(array_column($lines, 'supplied_value'));
        $status = $supplied === 0 ? 'open' : ($supplied >= $value ? 'closed' : 'partial');
        $remarks = implode('; ', array_unique(array_filter(array_column($lines, 'remarks')))) ?: null;
        Database::query(
            "INSERT INTO pending_orders (financial_year_id, order_no, order_date, customer_po_no, customer_id, branch_id, employee_id,
                                         expected_delivery_date, status, source, import_batch_id, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?, ?, ?)",
            [$h['fy_id'], $h['order_no'], $h['order_date'], $h['customer_po_no'], $h['customer_id'], $h['branch_id'], $h['employee_id'],
             $h['expected_date'], $status, $batchId, $remarks !== null ? mb_substr($remarks, 0, 500) : null, $this->uid(), $this->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare(
            'INSERT INTO pending_order_items (order_id, product_id, order_qty, supplied_qty, rate, order_value, supplied_value, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['order_qty'], $l['supplied_qty'], Money::toDecimal(Money::perUnit($l['order_value'], $l['order_qty'])),
                Money::toDecimal($l['order_value']), Money::toDecimal($l['supplied_value']), $l['remarks'] !== null ? mb_substr($l['remarks'], 0, 255) : null]);
        }
        return ['pending_orders', $id];
    }

    /** value x part / whole (quantities as decimal strings), half-up, in paise. */
    private static function share(int $value, string $part, string $whole): int
    {
        $p = (int) round((float) $part * 1000);
        $w = (int) round((float) $whole * 1000);
        if ($p === 0 || $w === 0) {
            return 0;
        }
        return $p >= $w ? $value : intdiv($value * $p * 2 + $w, $w * 2);
    }
}
