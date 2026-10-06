<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Database;
use App\Core\Money;
use App\Modules\Dashboard\DashboardContext;

/**
 * Pending samples (awaiting customer outcome) and pending DCs (goods out, not yet
 * invoiced / returned), as on the selected date. Sources: v_pending_sample_lines,
 * v_pending_dc_lines (line level, so a product filter applies).
 */
final class SampleDcKpi
{
    public function __construct(private readonly DashboardContext $ctx) {}

    /** @return array{samples: array{value: int, documents: int, customers: int}, dc: array{value: int, documents: int, customers: int}} */
    public function summary(): array
    {
        return [
            'samples' => $this->one('v_pending_sample_lines', 'sample_id', 'sample_value', 'document_date'),
            'dc'      => $this->one('v_pending_dc_lines', 'dc_id', 'dc_value', 'dc_date'),
        ];
    }

    /**
     * Pending documents for a drill-down list.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, count: int}
     */
    public function documents(string $kind, int $limit, int $offset): array
    {
        [$view, $idCol, $valueCol, $dateCol, $noCol] = $kind === 'dc'
            ? ['v_pending_dc_lines', 'dc_id', 'dc_value', 'dc_date', 'dc_no']
            : ['v_pending_sample_lines', 'sample_id', 'sample_value', 'document_date', 'document_no'];
        [$where, $params] = $this->where();
        $asOn = $this->ctx->asOn->format('Y-m-d');
        $agg = Database::fetch("SELECT COUNT(DISTINCT x.{$idCol}) AS n, COALESCE(SUM(x.{$valueCol}), 0) AS t FROM {$view} x WHERE x.{$dateCol} <= ? AND {$where}",
            array_merge([$asOn], $params));
        $rows = Database::fetchAll(
            "SELECT x.{$idCol} AS id, x.{$noCol} AS document_no, x.{$dateCol} AS document_date, DATEDIFF(?, x.{$dateCol}) AS age,
                    SUM(x.{$valueCol}) AS value, GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS products,
                    MAX(x.supply_status) AS supply_status, c.name AS customer, c.customer_code, b.branch_code, e.short_name AS employee
             FROM {$view} x
             JOIN products p ON p.id = x.product_id
             JOIN customers c ON c.id = x.customer_id
             JOIN branches b ON b.id = x.branch_id
             LEFT JOIN employees e ON e.id = x.employee_id
             WHERE x.{$dateCol} <= ? AND {$where}
             GROUP BY x.{$idCol}, x.{$noCol}, x.{$dateCol}, c.name, c.customer_code, b.branch_code, e.short_name
             ORDER BY x.{$dateCol}, x.{$idCol} LIMIT {$limit} OFFSET {$offset}",
            array_merge([$asOn, $asOn], $params)
        );
        return ['rows' => $rows, 'total' => Money::fromDb($agg['t']), 'count' => (int) $agg['n']];
    }

    /** @return array{value: int, documents: int, customers: int} */
    private function one(string $view, string $idCol, string $valueCol, string $dateCol): array
    {
        [$where, $params] = $this->where();
        $r = Database::fetch(
            "SELECT COALESCE(SUM(x.{$valueCol}), 0) AS v, COUNT(DISTINCT x.{$idCol}) AS d, COUNT(DISTINCT x.customer_id) AS c
             FROM {$view} x WHERE x.{$dateCol} <= ? AND {$where}",
            array_merge([$this->ctx->asOn->format('Y-m-d')], $params)
        );
        return ['value' => Money::fromDb($r['v']), 'documents' => (int) $r['d'], 'customers' => (int) $r['c']];
    }

    /** @return array{0: string, 1: list<int>} */
    private function where(): array
    {
        return $this->ctx->where(['branch' => 'x.branch_id', 'employee' => 'x.employee_id', 'customer' => 'x.customer_id', 'product' => 'x.product_id']);
    }
}
