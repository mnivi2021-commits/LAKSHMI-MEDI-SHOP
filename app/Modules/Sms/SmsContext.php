<?php

declare(strict_types=1);

namespace App\Modules\Sms;

use App\Core\Database;
use App\Core\DataScope;
use App\Core\Money;
use App\Modules\Dashboard\Kpi\OpenBills;

/**
 * "Send SMS" opened from a record (Screen 4): an enquiry offer, a purchase order or a
 * customer's payment dues. Loads the record inside the user's data scope and supplies
 * the template to start from plus the placeholder values for that record.
 */
final class SmsContext
{
    /** Default template (by name) for each kind of record. */
    public const TEMPLATES = ['enquiry' => 'Enquiry Offer', 'order' => 'Purchase Order Received', 'payment' => 'Payment Due'];

    /**
     * @return array{for: string, id: int, label: string, template: string, customer_code: ?string, mobile: ?string, name: ?string,
     *               customer_id: ?int, lead_id: ?int, vars: array<string, ?string>}|null
     */
    public static function load(string $for, int $id, array $user): ?array
    {
        if (!isset(self::TEMPLATES[$for]) || $id <= 0) {
            return null;
        }
        $scope = DataScope::for($user);
        [$w, $p] = $scope->where('x.branch_id', 'x.employee_id');
        $base = ['for' => $for, 'id' => $id, 'template' => self::TEMPLATES[$for]];

        if ($for === 'enquiry') {
            $l = Database::fetch(
                "SELECT x.*, c.customer_code, c.mobile AS c_mobile, c.name AS c_name, e.name AS employee_name
                 FROM leads x LEFT JOIN customers c ON c.id = x.customer_id LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.id = ? AND x.record_type = 'enquiry' AND x.deleted_at IS NULL AND {$w}", array_merge([$id], $p));
            if ($l === null) {
                return null;
            }
            $products = Database::value(
                "SELECT GROUP_CONCAT(COALESCE(p.name, i.description) ORDER BY i.id SEPARATOR ', ')
                 FROM lead_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.lead_id = ?", [$id]);
            return $base + [
                'label' => "Enquiry {$l['lead_number']}", 'customer_code' => $l['customer_code'], 'mobile' => $l['c_mobile'] ?? $l['mobile'],
                'name' => $l['contact_person'] ?: ($l['c_name'] ?? $l['name']), 'customer_id' => $l['customer_id'] !== null ? (int) $l['customer_id'] : null, 'lead_id' => (int) $l['id'],
                'vars' => [
                    'customer_name' => $l['c_name'] ?? $l['name'], 'enquiry_no' => $l['lead_number'], 'employee_name' => $l['employee_name'],
                    'products' => $products !== null ? mb_strimwidth((string) $products, 0, 80, '...') : null,
                    'amount' => $l['expected_value'] !== null ? SmsService::amount(Money::fromDb($l['expected_value'])) : null,
                ],
            ];
        }

        if ($for === 'order') {
            $o = Database::fetch(
                "SELECT x.*, c.customer_code, c.mobile, c.name AS c_name, e.name AS employee_name,
                        (SELECT SUM(order_value) FROM pending_order_items WHERE order_id = x.id) AS total
                 FROM pending_orders x JOIN customers c ON c.id = x.customer_id LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.id = ? AND x.deleted_at IS NULL AND {$w}", array_merge([$id], $p));
            if ($o === null) {
                return null;
            }
            return $base + [
                'label' => "Order {$o['order_no']}", 'customer_code' => $o['customer_code'], 'mobile' => $o['mobile'], 'name' => $o['c_name'],
                'customer_id' => (int) $o['customer_id'], 'lead_id' => null,
                'vars' => [
                    'customer_name' => $o['c_name'], 'order_no' => $o['order_no'], 'employee_name' => $o['employee_name'],
                    'po_ref' => $o['customer_po_no'] ?: ($o['reference_detail'] ?: null),
                    'amount' => $o['total'] !== null ? SmsService::amount(Money::fromDb($o['total'])) : null,
                    'delivery_date' => $o['expected_delivery_date'] ? date('d-m-Y', strtotime($o['expected_delivery_date'])) : null,
                ],
            ];
        }

        // payment: all open bills of one customer
        [$cw, $cp] = $scope->where('c.branch_id', 'c.employee_id');
        $c = Database::fetch(
            "SELECT c.id, c.customer_code, c.name, c.mobile, e.name AS employee_name
             FROM customers c LEFT JOIN employees e ON e.id = c.employee_id
             WHERE c.id = ? AND c.deleted_at IS NULL AND {$cw}", array_merge([$id], $cp));
        if ($c === null) {
            return null;
        }
        $bills = OpenBills::sql();
        $d = Database::fetch(
            "SELECT SUM(ob.balance) AS amount, COUNT(*) AS bills, MIN(ob.due_date) AS oldest_due,
                    SUBSTRING_INDEX(GROUP_CONCAT(ob.invoice_no ORDER BY ob.due_date, ob.invoice_date), ',', 1) AS first_invoice
             FROM {$bills} ob WHERE ob.customer_id = ?", [$id]);
        return $base + [
            'label' => "Payment due - {$c['name']}", 'customer_code' => $c['customer_code'], 'mobile' => $c['mobile'], 'name' => $c['name'],
            'customer_id' => (int) $c['id'], 'lead_id' => null,
            'vars' => [
                'customer_name' => $c['name'], 'employee_name' => $c['employee_name'],
                'amount' => $d && $d['amount'] !== null ? SmsService::amount(Money::fromDb($d['amount'])) : null,
                'bill_count' => $d && (int) $d['bills'] > 0 ? (string) $d['bills'] : null,
                'due_date' => $d && $d['oldest_due'] ? date('d-m-Y', strtotime($d['oldest_due'])) : null,
                'invoice_no' => $d['first_invoice'] ?? null,
            ],
        ];
    }
}
