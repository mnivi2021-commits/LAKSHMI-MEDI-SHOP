<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Audit;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use DateTimeImmutable;
use PDOException;

/**
 * Dashboard "ADD" panel: validated entry of the source transactions behind the
 * KPIs. Every type re-checks permission and data scope on the server; amounts
 * are handled as integer paise; the financial year is derived from the date.
 *
 * handle() returns [httpStatus, body]; body = ['success'=>true,'message'=>..] or
 * ['success'=>false,'errors'=>[field => message]].
 */
final class QuickAddService
{
    public const TYPES = [
        'target'        => ['label' => 'Sales Target',  'permission' => 'targets.add'],
        'sale'          => ['label' => 'Sale',          'permission' => 'sales.add'],
        'collection'    => ['label' => 'Collection',    'permission' => 'collections.add'],
        'pending_order' => ['label' => 'Pending Order', 'permission' => 'pending_orders.add'],
        'sample'        => ['label' => 'Sample',        'permission' => 'samples.add'],
        'dc'            => ['label' => 'DC',            'permission' => 'dc.add'],
    ];

    private const DOC_NO = '/^[A-Za-z0-9][A-Za-z0-9\/\-_. ]{0,39}$/';
    private const PAYMENT_MODES = ['neft', 'rtgs', 'imps', 'upi', 'cheque', 'cash', 'dd', 'other'];
    private const SUPPLY_STATUSES = ['not_supplied', 'partially_supplied', 'supplied'];

    /** @var array<string, string> */
    private array $errors = [];
    private DataScope $scope;
    private DateTimeImmutable $today;

    /** @param array<string, mixed> $user */
    public function __construct(private readonly array $user, ?DateTimeImmutable $today = null)
    {
        $this->scope = DataScope::for($user);
        $this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /** Types this user may add (for showing tabs). @return array<string, string> */
    public static function allowedTypes(array $user): array
    {
        $out = [];
        foreach (self::TYPES as $type => $def) {
            if (Gate::allows($def['permission'], $user)) {
                $out[$type] = $def['label'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $in raw form input
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $type, array $in): array
    {
        if (!isset(self::TYPES[$type])) {
            return [404, ['success' => false, 'message' => 'Unknown entry type.']];
        }
        if (!Gate::allows(self::TYPES[$type]['permission'], $this->user)) {
            return [403, ['success' => false, 'message' => 'You do not have permission to add ' . self::TYPES[$type]['label'] . ' records.']];
        }

        $this->errors = [];
        $in = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $in);

        try {
            $result = match ($type) {
                'target'        => $this->target($in),
                'sale'          => $this->sale($in),
                'collection'    => $this->collection($in),
                'pending_order' => $this->pendingOrder($in),
                'sample'        => $this->sample($in),
                'dc'            => $this->dc($in),
            };
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {   // unique key race: same number saved concurrently
                return [422, ['success' => false, 'errors' => ['_' => 'This document number already exists in this financial year.']]];
            }
            throw $e;
        }

        if ($this->errors) {
            return [422, ['success' => false, 'errors' => $this->errors]];
        }
        return [200, ['success' => true] + $result];
    }

    // =========================================================================
    // Types
    // =========================================================================

    /** Monthly target, or a whole-year amount split exactly across 12 months. */
    private function target(array $in): ?array
    {
        $employee = $this->repInScope($in['employee_id'] ?? '', true);
        $fy = Database::fetch('SELECT * FROM financial_years WHERE id = ?', [(int) ($in['fy_id'] ?? 0)]);
        if ($fy === null) {
            $this->errors['fy_id'] = 'Choose a financial year.';
        } elseif ((int) $fy['is_locked'] === 1) {
            $this->errors['fy_id'] = "{$fy['label']} is locked.";
        }

        $month = (string) ($in['month'] ?? '');
        $months = [];
        if ($fy !== null) {
            $d = new DateTimeImmutable($fy['start_date']);
            for ($i = 0; $i < 12; $i++) {
                $months[] = $d->modify("+{$i} month")->format('Y-m-01');
            }
            if ($month === 'all') {
                $targetMonths = $months;
            } elseif (preg_match('/^\d{4}-\d{2}$/', $month) && in_array($month . '-01', $months, true)) {
                $targetMonths = [$month . '-01'];
            } else {
                $this->errors['month'] = 'Choose a month of this financial year, or "All 12 months".';
            }
        }

        $sales = $this->amount($in, 'sales_target', 'Sales target', true);
        $coll = $this->amount($in, 'collection_target', 'Collection target', true);
        if ($sales === 0 && $coll === 0 && !isset($this->errors['sales_target'])) {
            $this->errors['sales_target'] = 'Enter a sales target and/or a collection target.';
        }
        if ($this->errors) {
            return null;
        }

        $n = count($targetMonths);
        $salesParts = Money::split($sales, $n);
        $collParts = Money::split($coll, $n);

        $existing = (int) Database::value(
            'SELECT COUNT(*) FROM sales_targets WHERE employee_id = ? AND target_month IN (' . implode(',', array_fill(0, $n, '?')) . ')',
            array_merge([$employee['id']], $targetMonths)
        );
        if ($existing > 0 && !Gate::allows('targets.edit', $this->user)) {
            $this->errors['month'] = 'A target already exists for this period and you do not have permission to change targets.';
            return null;
        }

        Database::transaction(function () use ($fy, $employee, $targetMonths, $salesParts, $collParts): void {
            $stmt = Database::connection()->prepare(
                'INSERT INTO sales_targets (financial_year_id, employee_id, branch_id, target_month, sales_target, collection_target, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE sales_target = VALUES(sales_target), collection_target = VALUES(collection_target),
                                         financial_year_id = VALUES(financial_year_id), branch_id = VALUES(branch_id), updated_by = VALUES(updated_by)'
            );
            foreach ($targetMonths as $i => $m) {
                $stmt->execute([$fy['id'], $employee['id'], $employee['branch_id'], $m,
                    Money::toDecimal($salesParts[$i]), Money::toDecimal($collParts[$i]), $this->user['id'], $this->user['id']]);
            }
        });

        Audit::log('target.saved', 'targets', (int) $employee['id'], null, [
            'employee' => $employee['name'], 'fy' => $fy['label'], 'months' => count($targetMonths) === 12 ? 'all' : $targetMonths[0],
            'sales_target_total' => Money::toDecimal($sales), 'collection_target_total' => Money::toDecimal($coll), 'replaced_rows' => $existing,
        ]);

        $what = count($targetMonths) === 12 ? "{$fy['label']} (12 months)" : (new DateTimeImmutable($targetMonths[0]))->format('M Y');
        return ['message' => "Target saved for {$employee['name']} – {$what}" . ($existing ? ' (previous target replaced).' : '.')];
    }

    private function sale(array $in): ?array
    {
        $date = $this->date($in, 'invoice_date', 'Invoice date');
        $fy = $date ? $this->fyFor($date, 'invoice_date') : null;
        $no = $this->docNo($in, 'invoice_no', 'Invoice number');
        $customer = $this->customerInScope($in['customer_id'] ?? '');
        $employeeId = $this->employeeFor($in, $customer);
        $product = $this->product($in['product_id'] ?? '');
        $qty = $this->quantity($in, 'quantity', 'Quantity');
        $taxable = $this->amount($in, 'taxable_amount', 'Taxable value', false);

        $gst = (string) ($in['gst_rate'] ?? '');
        if ($gst === '' && $product !== null) {
            $gst = rtrim(rtrim((string) $product['gst_rate'], '0'), '.') ?: '0';
        }
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $gst)) {
            $this->errors['gst_rate'] = 'GST % must be a number between 0 and 99.99.';
        }

        $due = null;
        if (($in['due_date'] ?? '') !== '') {
            $due = $this->date($in, 'due_date', 'Due date', false);
            if ($due && $date && $due < $date) {
                $this->errors['due_date'] = 'Due date cannot be before the invoice date.';
            }
        }
        if ($fy && $no !== null && Database::value("SELECT 1 FROM sales_invoices WHERE financial_year_id = ? AND document_type = 'invoice' AND invoice_no = ?", [$fy['id'], $no])) {
            $this->errors['invoice_no'] = "Invoice {$no} already exists in {$fy['label']}.";
        }
        if ($this->errors) {
            return null;
        }

        $tax = Money::percentOf($taxable, $gst);
        $total = $taxable + $tax;
        $due ??= (new DateTimeImmutable($date))->modify('+' . (int) $customer['credit_days'] . ' days')->format('Y-m-d');

        $id = Database::transaction(function () use ($fy, $no, $date, $due, $customer, $employeeId, $product, $qty, $taxable, $tax, $total, $gst): int {
            Database::query(
                "INSERT INTO sales_invoices (financial_year_id, document_type, invoice_no, invoice_date, due_date, customer_id, branch_id, employee_id,
                                             taxable_amount, tax_amount, round_off, total_amount, source, created_by, updated_by)
                 VALUES (?, 'invoice', ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'manual', ?, ?)",
                [$fy['id'], $no, $date, $due, $customer['id'], $customer['branch_id'], $employeeId,
                 Money::toDecimal($taxable), Money::toDecimal($tax), Money::toDecimal($total), $this->user['id'], $this->user['id']]
            );
            $id = (int) Database::connection()->lastInsertId();
            Database::query(
                'INSERT INTO sales_invoice_items (invoice_id, product_id, quantity, rate, taxable_amount, gst_rate, tax_amount, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $product['id'], $qty, Money::toDecimal(Money::perUnit($taxable, $qty)), Money::toDecimal($taxable), $gst,
                 Money::toDecimal($tax), Money::toDecimal($total)]
            );
            return $id;
        });

        Audit::log('sale.created', 'sales', $id, null, ['invoice_no' => $no, 'date' => $date, 'customer_id' => (int) $customer['id'],
            'taxable' => Money::toDecimal($taxable), 'total' => Money::toDecimal($total), 'source' => 'dashboard']);
        return ['message' => "Invoice {$no} saved: " . inr(Money::toDecimal($total)) . ' (taxable ' . inr(Money::toDecimal($taxable)) . ').', 'id' => $id];
    }

    /** Receipt, auto-allocated to the customer's oldest open bills so outstanding stays correct. */
    private function collection(array $in): ?array
    {
        $date = $this->date($in, 'receipt_date', 'Receipt date');
        $fy = $date ? $this->fyFor($date, 'receipt_date') : null;
        $no = $this->docNo($in, 'receipt_no', 'Receipt number');
        $customer = $this->customerInScope($in['customer_id'] ?? '');
        $employeeId = $this->employeeFor($in, $customer);
        $amount = $this->amount($in, 'amount', 'Amount', false);

        $mode = (string) ($in['payment_mode'] ?? '');
        if (!in_array($mode, self::PAYMENT_MODES, true)) {
            $this->errors['payment_mode'] = 'Choose a payment mode.';
        }
        $reference = mb_substr((string) ($in['reference_no'] ?? ''), 0, 60);
        $autoAllocate = ($in['auto_allocate'] ?? '1') === '1';

        if ($fy && $no !== null && Database::value('SELECT 1 FROM collections WHERE financial_year_id = ? AND receipt_no = ?', [$fy['id'], $no])) {
            $this->errors['receipt_no'] = "Receipt {$no} already exists in {$fy['label']}.";
        }
        if ($this->errors) {
            return null;
        }

        // Cheques/DDs are "received" until cleared; electronic payments are cleared.
        $status = in_array($mode, ['cheque', 'dd'], true) ? 'received' : 'cleared';

        [$id, $allocated, $bills] = Database::transaction(function () use ($fy, $no, $date, $customer, $employeeId, $amount, $mode, $reference, $status, $autoAllocate): array {
            Database::query(
                "INSERT INTO collections (financial_year_id, receipt_no, receipt_date, customer_id, branch_id, employee_id, amount,
                                          payment_mode, reference_no, status, source, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?)",
                [$fy['id'], $no, $date, $customer['id'], $customer['branch_id'], $employeeId, Money::toDecimal($amount),
                 $mode, $reference !== '' ? $reference : null, $status, $this->user['id'], $this->user['id']]
            );
            $id = (int) Database::connection()->lastInsertId();
            if (!$autoAllocate) {
                return [$id, 0, 0];
            }

            // Lock this customer's invoices so two receipts cannot allocate the same balance.
            Database::query('SELECT id FROM sales_invoices WHERE customer_id = ? FOR UPDATE', [$customer['id']]);
            $open = Database::fetchAll(
                'SELECT invoice_id, balance FROM v_invoice_balances WHERE customer_id = ? AND balance > 0 ORDER BY invoice_date, invoice_id',
                [$customer['id']]
            );
            $left = $amount;
            $allocated = 0;
            $bills = 0;
            $ins = Database::connection()->prepare('INSERT INTO collection_allocations (collection_id, invoice_id, amount, created_by) VALUES (?, ?, ?, ?)');
            foreach ($open as $bill) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (int) Money::parse((string) $bill['balance']));
                if ($take <= 0) {
                    continue;
                }
                $ins->execute([$id, $bill['invoice_id'], Money::toDecimal($take), $this->user['id']]);
                $left -= $take;
                $allocated += $take;
                $bills++;
            }
            return [$id, $allocated, $bills];
        });

        Audit::log('collection.created', 'collections', $id, null, ['receipt_no' => $no, 'date' => $date, 'customer_id' => (int) $customer['id'],
            'amount' => Money::toDecimal($amount), 'mode' => $mode, 'allocated' => Money::toDecimal($allocated), 'bills' => $bills, 'source' => 'dashboard']);

        $msg = "Receipt {$no} saved: " . inr(Money::toDecimal($amount)) . '.';
        if ($autoAllocate) {
            $msg .= $bills > 0 ? ' Adjusted against ' . $bills . ' oldest bill(s)' : ' No open bills to adjust';
            $msg .= $amount > $allocated ? '; ' . inr(Money::toDecimal($amount - $allocated)) . ' kept on account.' : '.';
        }
        return ['message' => $msg, 'id' => $id];
    }

    private function pendingOrder(array $in): ?array
    {
        $date = $this->date($in, 'order_date', 'Order date');
        $fy = $date ? $this->fyFor($date, 'order_date') : null;
        $no = $this->docNo($in, 'order_no', 'Order number');
        $customer = $this->customerInScope($in['customer_id'] ?? '');
        $employeeId = $this->employeeFor($in, $customer);
        $product = $this->product($in['product_id'] ?? '');
        $qty = $this->quantity($in, 'order_qty', 'Order quantity');
        $value = $this->amount($in, 'order_value', 'Order value', false);
        $suppliedQty = ($in['supplied_qty'] ?? '') === '' ? '0' : $this->quantity($in, 'supplied_qty', 'Supplied quantity', true);
        $suppliedValue = ($in['supplied_value'] ?? '') === '' ? 0 : $this->amount($in, 'supplied_value', 'Supplied value', true);

        if ($qty !== null && $suppliedQty !== null && (float) $suppliedQty > (float) $qty) {
            $this->errors['supplied_qty'] = 'Supplied quantity cannot exceed the order quantity.';
        }
        if ($value !== null && $suppliedValue !== null && $suppliedValue > $value) {
            $this->errors['supplied_value'] = 'Supplied value cannot exceed the order value.';
        }
        $delivery = null;
        if (($in['expected_delivery_date'] ?? '') !== '') {
            $delivery = $this->date($in, 'expected_delivery_date', 'Expected delivery', false);
            if ($delivery && $date && $delivery < $date) {
                $this->errors['expected_delivery_date'] = 'Expected delivery cannot be before the order date.';
            }
        }
        $remarks = mb_substr((string) ($in['remarks'] ?? ''), 0, 255);
        if ($fy && $no !== null && Database::value('SELECT 1 FROM pending_orders WHERE financial_year_id = ? AND order_no = ?', [$fy['id'], $no])) {
            $this->errors['order_no'] = "Order {$no} already exists in {$fy['label']}.";
        }
        if ($this->errors) {
            return null;
        }

        $status = $suppliedValue === 0 ? 'open' : ($suppliedValue >= $value ? 'closed' : 'partial');
        $id = Database::transaction(function () use ($fy, $no, $date, $customer, $employeeId, $delivery, $status, $remarks, $product, $qty, $suppliedQty, $value, $suppliedValue): int {
            Database::query(
                "INSERT INTO pending_orders (financial_year_id, order_no, order_date, customer_id, branch_id, employee_id, expected_delivery_date,
                                             status, source, remarks, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?)",
                [$fy['id'], $no, $date, $customer['id'], $customer['branch_id'], $employeeId, $delivery, $status,
                 $remarks !== '' ? $remarks : null, $this->user['id'], $this->user['id']]
            );
            $id = (int) Database::connection()->lastInsertId();
            Database::query(
                'INSERT INTO pending_order_items (order_id, product_id, order_qty, supplied_qty, rate, order_value, supplied_value) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $product['id'], $qty, $suppliedQty, Money::toDecimal(Money::perUnit($value, $qty)), Money::toDecimal($value), Money::toDecimal($suppliedValue)]
            );
            return $id;
        });

        Audit::log('pending_order.created', 'pending_orders', $id, null, ['order_no' => $no, 'date' => $date, 'customer_id' => (int) $customer['id'],
            'order_value' => Money::toDecimal($value), 'supplied_value' => Money::toDecimal($suppliedValue), 'source' => 'dashboard']);
        $pending = $value - $suppliedValue;
        return ['message' => "Order {$no} saved. Pending value " . inr(Money::toDecimal($pending)) . ($status === 'closed' ? ' (fully supplied, so not counted as pending).' : '.'), 'id' => $id];
    }

    private function sample(array $in): ?array
    {
        $date = $this->date($in, 'document_date', 'Sample date');
        $fy = $date ? $this->fyFor($date, 'document_date') : null;
        $no = $this->docNo($in, 'document_no', 'Sample number');
        $customer = $this->customerInScope($in['customer_id'] ?? '');
        $employeeId = $this->employeeFor($in, $customer);
        $product = $this->product($in['product_id'] ?? '');
        $qty = $this->quantity($in, 'quantity', 'Quantity');
        $value = $this->amount($in, 'sample_value', 'Sample value', true);
        $supply = (string) ($in['supply_status'] ?? 'not_supplied');
        if (!in_array($supply, self::SUPPLY_STATUSES, true)) {
            $this->errors['supply_status'] = 'Choose a supply status.';
        }
        $remarks = mb_substr((string) ($in['remarks'] ?? ''), 0, 255);
        if ($fy && $no !== null && Database::value('SELECT 1 FROM samples WHERE financial_year_id = ? AND document_no = ?', [$fy['id'], $no])) {
            $this->errors['document_no'] = "Sample {$no} already exists in {$fy['label']}.";
        }
        if ($this->errors) {
            return null;
        }

        $approved = Gate::allows('samples.approve', $this->user);
        $id = Database::transaction(function () use ($fy, $no, $date, $customer, $employeeId, $supply, $remarks, $product, $qty, $value, $approved): int {
            Database::query(
                // Same rule as the Requests screen: only a manager's own sample is approved at once.
                "INSERT INTO samples (financial_year_id, document_no, document_date, customer_id, branch_id, employee_id, supply_status, pending_status,
                                      approval_status, approved_by, approved_at, remarks, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)",
                [$fy['id'], $no, $date, $customer['id'], $customer['branch_id'], $employeeId, $supply,
                 $approved ? 'approved' : 'requested', $approved ? $this->user['id'] : null, $approved ? date('Y-m-d H:i:s') : null,
                 $remarks !== '' ? $remarks : null, $this->user['id'], $this->user['id']]
            );
            $id = (int) Database::connection()->lastInsertId();
            Database::query('INSERT INTO sample_items (sample_id, product_id, quantity, sample_value) VALUES (?, ?, ?, ?)',
                [$id, $product['id'], $qty, Money::toDecimal($value)]);
            return $id;
        });

        Audit::log('sample.created', 'samples', $id, null, ['document_no' => $no, 'date' => $date, 'customer_id' => (int) $customer['id'],
            'value' => Money::toDecimal($value), 'source' => 'dashboard']);
        return ['message' => "Sample {$no} saved (" . inr(Money::toDecimal($value)) . ').', 'id' => $id];
    }

    private function dc(array $in): ?array
    {
        $date = $this->date($in, 'dc_date', 'DC date');
        $fy = $date ? $this->fyFor($date, 'dc_date') : null;
        $no = $this->docNo($in, 'dc_no', 'DC number');
        $customer = $this->customerInScope($in['customer_id'] ?? '');
        $employeeId = $this->employeeFor($in, $customer);
        $product = $this->product($in['product_id'] ?? '');
        $qty = $this->quantity($in, 'quantity', 'Quantity');
        $value = $this->amount($in, 'dc_value', 'DC value', true);
        $remarks = mb_substr((string) ($in['remarks'] ?? ''), 0, 255);
        if ($fy && $no !== null && Database::value('SELECT 1 FROM dc_records WHERE financial_year_id = ? AND dc_no = ?', [$fy['id'], $no])) {
            $this->errors['dc_no'] = "DC {$no} already exists in {$fy['label']}.";
        }
        if ($this->errors) {
            return null;
        }

        $id = Database::transaction(function () use ($fy, $no, $date, $customer, $employeeId, $remarks, $product, $qty, $value): int {
            Database::query(
                "INSERT INTO dc_records (financial_year_id, dc_no, dc_date, customer_id, branch_id, employee_id, supply_status, pending_status, remarks, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'supplied', 'pending', ?, ?, ?)",
                [$fy['id'], $no, $date, $customer['id'], $customer['branch_id'], $employeeId, $remarks !== '' ? $remarks : null, $this->user['id'], $this->user['id']]
            );
            $id = (int) Database::connection()->lastInsertId();
            Database::query('INSERT INTO dc_items (dc_id, product_id, quantity, dc_value) VALUES (?, ?, ?, ?)',
                [$id, $product['id'], $qty, Money::toDecimal($value)]);
            return $id;
        });

        Audit::log('dc.created', 'dc', $id, null, ['dc_no' => $no, 'date' => $date, 'customer_id' => (int) $customer['id'],
            'value' => Money::toDecimal($value), 'source' => 'dashboard']);
        return ['message' => "DC {$no} saved (" . inr(Money::toDecimal($value)) . ').', 'id' => $id];
    }

    // =========================================================================
    // Field helpers (each records an error and returns null on failure)
    // =========================================================================

    private function date(array $in, string $field, string $label, bool $notFuture = true): ?string
    {
        $v = (string) ($in[$field] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($v === '' || $d === false || $d->format('Y-m-d') !== $v) {
            $this->errors[$field] = "{$label} is required (DD-MM-YYYY).";
            return null;
        }
        if ($notFuture && $d > $this->today) {
            $this->errors[$field] = "{$label} cannot be in the future.";
            return null;
        }
        return $v;
    }

    /** @return array<string, mixed>|null */
    private function fyFor(string $date, string $field): ?array
    {
        $fy = Database::fetch('SELECT id, label, is_locked FROM financial_years WHERE ? BETWEEN start_date AND end_date', [$date]);
        if ($fy === null) {
            $this->errors[$field] = 'No financial year is set up for this date.';
            return null;
        }
        if ((int) $fy['is_locked'] === 1) {
            $this->errors[$field] = "{$fy['label']} is locked; entries are not allowed.";
            return null;
        }
        return $fy;
    }

    private function docNo(array $in, string $field, string $label): ?string
    {
        $v = (string) ($in[$field] ?? '');
        if ($v === '') {
            $this->errors[$field] = "{$label} is required.";
            return null;
        }
        if (!preg_match(self::DOC_NO, $v)) {
            $this->errors[$field] = "{$label}: up to 40 letters, digits, / - _ . or spaces.";
            return null;
        }
        return $v;
    }

    /** Paise; $allowZero for optional/zero-able amounts. */
    private function amount(array $in, string $field, string $label, bool $allowZero): ?int
    {
        $raw = (string) ($in[$field] ?? '');
        if ($raw === '' && $allowZero) {
            return 0;
        }
        $p = Money::parse($raw);
        if ($p === null) {
            $this->errors[$field] = "{$label} must be an amount like 12500 or 12500.50.";
            return null;
        }
        if ($p === 0 && !$allowZero) {
            $this->errors[$field] = "{$label} must be more than zero.";
            return null;
        }
        return $p;
    }

    private function quantity(array $in, string $field, string $label, bool $allowZero = false): ?string
    {
        $v = str_replace(',', '', (string) ($in[$field] ?? ''));
        if (!preg_match('/^\d{1,11}(\.\d{1,3})?$/', $v) || (!$allowZero && (float) $v <= 0)) {
            $this->errors[$field] = "{$label} must be a number" . ($allowZero ? '' : ' greater than zero') . ' (up to 3 decimals).';
            return null;
        }
        return $v;
    }

    /** @return array<string, mixed>|null */
    private function customerInScope(mixed $id): ?array
    {
        $c = ctype_digit((string) $id) ? Database::fetch(
            "SELECT id, name, branch_id, employee_id, credit_days FROM customers WHERE id = ? AND deleted_at IS NULL AND status <> 'blocked'",
            [(int) $id]
        ) : null;
        if ($c === null) {
            $this->errors['customer_id'] = 'Choose a customer.';
            return null;
        }
        if (!$this->scope->allowsBranch((int) $c['branch_id']) || !$this->scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
            $this->errors['customer_id'] = 'You do not have access to this customer.';
            return null;
        }
        return $c;
    }

    /** Explicit employee (must be a sales rep in scope) or the customer's assigned employee. */
    private function employeeFor(array $in, ?array $customer): ?int
    {
        if (($in['employee_id'] ?? '') !== '') {
            $rep = $this->repInScope($in['employee_id'], false);
            return $rep !== null ? (int) $rep['id'] : null;
        }
        if ($customer === null) {
            return null;
        }
        $id = $customer['employee_id'] !== null ? (int) $customer['employee_id'] : null;
        if ($id === null && $this->scope->employeeIds !== null) {
            $this->errors['employee_id'] = 'This customer has no sales employee; choose one.';
        }
        return $id;
    }

    /** @return array<string, mixed>|null */
    private function repInScope(mixed $id, bool $required): ?array
    {
        if ((string) $id === '' && !$required) {
            return null;
        }
        $e = ctype_digit((string) $id) ? Database::fetch(
            "SELECT id, name, branch_id FROM employees WHERE id = ? AND deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1",
            [(int) $id]
        ) : null;
        if ($e === null) {
            $this->errors['employee_id'] = 'Choose a sales employee.';
            return null;
        }
        if (!$this->scope->allowsBranch((int) $e['branch_id']) || !$this->scope->allowsEmployee((int) $e['id'])) {
            $this->errors['employee_id'] = 'You do not have access to this employee.';
            return null;
        }
        return $e;
    }

    /** @return array<string, mixed>|null */
    private function product(mixed $id): ?array
    {
        $p = ctype_digit((string) $id) ? Database::fetch("SELECT id, name, gst_rate FROM products WHERE id = ? AND deleted_at IS NULL AND status = 'active'", [(int) $id]) : null;
        if ($p === null) {
            $this->errors['product_id'] = 'Choose a product.';
        }
        return $p;
    }
}
