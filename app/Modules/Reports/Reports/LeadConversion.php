<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class LeadConversion extends Report
{
    public function key(): string { return 'lead-conversion'; }
    public function title(): string { return 'Lead conversion'; }
    public function group(): string { return 'Leads & follow-up'; }
    public function description(): string { return 'Leads received in the period per source: still open, won, lost and conversion rate.'; }
    public function permission(): string { return 'leads.view'; }
    public function filters(): array { return ['period', 'branch', 'employee']; }

    public function columns(): array
    {
        return ['source' => ['Source', 'text'], 'leads' => ['Leads', 'int'], 'open' => ['Open', 'int'], 'won' => ['Won', 'int'], 'lost' => ['Lost', 'int'],
                'rate' => ['Won of closed', 'pct'], 'open_value' => ['Open expected value', 'money']];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('l.branch_id', 'l.employee_id');
        $rows = array_map(static fn ($r) => [
            'source' => $r['source'] ?? 'Not given', 'leads' => (int) $r['n'], 'open' => (int) $r['open'], 'won' => (int) $r['won'], 'lost' => (int) $r['lost'],
            'rate' => (int) $r['won'] + (int) $r['lost'] > 0 ? round((int) $r['won'] * 100 / ((int) $r['won'] + (int) $r['lost']), 1) : null,
            'open_value' => self::paise($r['open_value']),
        ], Database::fetchAll(
            "SELECT s.name AS source, COUNT(*) AS n, SUM(l.status NOT IN ('won','lost')) AS open, SUM(l.status = 'won') AS won, SUM(l.status = 'lost') AS lost,
                    COALESCE(SUM(IF(l.status NOT IN ('won','lost'), l.expected_value, 0)), 0) AS open_value
             FROM leads l LEFT JOIN lead_sources s ON s.id = l.source_id
             WHERE l.deleted_at IS NULL AND l.created_at >= ? AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY) AND {$w}
             GROUP BY s.id, s.name ORDER BY COUNT(*) DESC",
            array_merge([$f->from . ' 00:00:00', $f->to], $p)
        ));
        return $rows;
    }
}
