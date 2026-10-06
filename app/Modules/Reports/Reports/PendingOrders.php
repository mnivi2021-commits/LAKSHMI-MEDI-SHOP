<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class PendingOrders extends Report
{
    public function key(): string { return 'pending-orders'; }
    public function title(): string { return 'Pending orders'; }
    public function group(): string { return 'Orders & supply'; }
    public function description(): string { return 'Order lines not yet fully supplied, oldest first, as on a date.'; }
    public function permission(): string { return 'pending_orders.view'; }
    public function filters(): array { return ['as_on', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['date' => ['Order date', 'date'], 'order' => ['Order', 'text'], 'age' => ['Age (days)', 'int'], 'customer' => ['Customer', 'text'],
                'product' => ['Product', 'text'], 'ordered' => ['Ordered', 'qty'], 'supplied' => ['Supplied', 'qty'], 'pending_qty' => ['Pending qty', 'qty'],
                'value' => ['Pending value', 'money'], 'delivery' => ['Expected', 'date']];
    }

    public function noTotal(): array
    {
        return ['age', 'ordered', 'supplied', 'pending_qty'];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('v.branch_id', 'v.employee_id', 'v.customer_id');
        return array_map(static fn ($r) => [
            'date' => $r['order_date'], 'order' => $r['order_no'], 'age' => (int) $r['age'], 'customer' => "{$r['customer']} ({$r['customer_code']})",
            'product' => $r['product'], 'ordered' => self::qty($r['order_qty']), 'supplied' => self::qty($r['supplied_qty']), 'pending_qty' => self::qty($r['pending_qty']),
            'value' => self::paise($r['pending_value']), 'delivery' => $r['expected_delivery_date'],
        ], Database::fetchAll(
            "SELECT v.*, DATEDIFF(?, v.order_date) AS age, c.name AS customer, c.customer_code, pr.name AS product
             FROM v_pending_order_lines v JOIN customers c ON c.id = v.customer_id JOIN products pr ON pr.id = v.product_id
             WHERE v.order_date <= ? AND {$w} ORDER BY v.order_date, v.order_no, pr.name",
            array_merge([$f->asOn, $f->asOn], $p)
        ));
    }

    private static function qty(mixed $v): string
    {
        return rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    }
}
