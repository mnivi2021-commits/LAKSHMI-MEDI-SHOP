<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class FollowupsDue extends Report
{
    public function key(): string { return 'followups-due'; }
    public function title(): string { return 'Follow-ups due'; }
    public function group(): string { return 'Leads & follow-up'; }
    public function description(): string { return 'Pending follow-up calls and visits due on or before a date, most overdue first.'; }
    public function permission(): string { return 'leads.view'; }
    public function filters(): array { return ['as_on', 'branch', 'employee']; }

    public function columns(): array
    {
        return ['due' => ['Due', 'text'], 'overdue' => ['Days overdue', 'int'], 'type' => ['Type', 'text'], 'who' => ['Lead / customer', 'text'],
                'mobile' => ['Mobile', 'text'], 'employee' => ['Employee', 'text'], 'notes' => ['Notes', 'text']];
    }

    public function noTotal(): array
    {
        return ['overdue'];
    }

    public function rows(ReportFilters $f): array
    {
        [$w, $p] = $f->where('x.branch_id', 'x.employee_id');
        return array_map(static fn ($r) => [
            'due' => date('d-m-Y H:i', strtotime($r['followup_at'])), 'overdue' => max(0, (int) $r['overdue']), 'type' => ucfirst($r['followup_type']),
            'who' => $r['who'], 'mobile' => $r['mobile'] ?? '', 'employee' => $r['employee'] ?? '', 'notes' => (string) $r['notes'],
        ], Database::fetchAll(
            "SELECT x.*, DATEDIFF(?, DATE(x.followup_at)) AS overdue, e.short_name AS employee FROM (
                 SELECT fu.followup_at, fu.followup_type, fu.notes, COALESCE(fu.employee_id, l.employee_id, c.employee_id) AS employee_id,
                        COALESCE(l.branch_id, c.branch_id) AS branch_id,
                        COALESCE(CONCAT('Lead ', l.lead_number, ' · ', l.name), CONCAT(c.name, ' (', c.customer_code, ')')) AS who,
                        COALESCE(l.mobile, c.mobile) AS mobile
                 FROM followups fu LEFT JOIN leads l ON l.id = fu.lead_id LEFT JOIN customers c ON c.id = fu.customer_id
                 WHERE fu.status = 'pending' AND fu.deleted_at IS NULL AND fu.followup_at < DATE_ADD(?, INTERVAL 1 DAY)
             ) x LEFT JOIN employees e ON e.id = x.employee_id
             WHERE {$w} ORDER BY x.followup_at",
            array_merge([$f->asOn, $f->asOn], $p)
        ));
    }
}
