<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Core\Gate;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class PendingSamplesDc extends Report
{
    public function key(): string { return 'pending-samples-dc'; }
    public function title(): string { return 'Sample / Open DC details'; }
    public function group(): string { return 'Rep-wise details'; }
    public function description(): string { return 'Samples awaiting the customer\'s decision and DCs not yet invoiced, as on a date.'; }
    public function permission(): string { return 'samples.view'; }
    public function filters(): array { return ['as_on', 'branch', 'employee', 'customer']; }

    public function columns(): array
    {
        return ['rep' => ['Rep', 'text'], 'type' => ['Type', 'text'], 'date' => ['Date', 'date'], 'doc' => ['Document', 'text'], 'age' => ['Age (days)', 'int'],
                'customer' => ['Customer', 'text'], 'product' => ['Product', 'text'], 'qty' => ['Quantity', 'qty'], 'price' => ['Price', 'money'],
                'value' => ['Value', 'money']];
    }

    public function noTotal(): array
    {
        return ['age', 'qty', 'price'];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('x.branch_id', 'x.employee_id', 'x.customer_id');
        $parts = [];
        $params = [];
        $parts[] = "SELECT 'Sample' AS type, x.document_date AS d, x.document_no AS doc, x.customer_id, x.employee_id, x.product_id, x.quantity, x.sample_value AS value
                    FROM v_pending_sample_lines x WHERE x.document_date <= ? AND {$w}";
        $params = array_merge([$f->asOn], $p);
        if (Gate::allows('dc.view', $f->user)) {
            $parts[] = "SELECT 'DC', x.dc_date, x.dc_no, x.customer_id, x.employee_id, x.product_id, x.quantity, x.dc_value
                        FROM v_pending_dc_lines x WHERE x.dc_date <= ? AND {$w}";
            $params = array_merge($params, [$f->asOn], $p);
        }
        return array_map(static fn ($r) => [
            'rep' => $r['rep'] ?? '', 'price' => (float) $r['quantity'] > 0 ? (int) round(self::paise($r['value']) / (float) $r['quantity']) : 0,
            'type' => $r['type'], 'date' => $r['d'], 'doc' => $r['doc'], 'age' => (int) $r['age'], 'customer' => "{$r['customer']} ({$r['customer_code']})",
            'product' => $r['product'], 'qty' => rtrim(rtrim((string) $r['quantity'], '0'), '.') ?: '0', 'value' => self::paise($r['value']),
        ], Database::fetchAll(
            'SELECT u.*, DATEDIFF(?, u.d) AS age, c.name AS customer, c.customer_code, pr.name AS product, COALESCE(e.short_name, e.name) AS rep
             FROM (' . implode(' UNION ALL ', $parts) . ') u JOIN customers c ON c.id = u.customer_id JOIN products pr ON pr.id = u.product_id
             LEFT JOIN employees e ON e.id = u.employee_id
             ORDER BY u.d, u.doc',
            array_merge([$f->asOn], $params)
        ));
    }
}
