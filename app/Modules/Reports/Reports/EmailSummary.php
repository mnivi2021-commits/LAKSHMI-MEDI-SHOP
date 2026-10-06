<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Modules\Mail\MailQuery;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class EmailSummary extends Report
{
    public function key(): string { return 'email-summary'; }
    public function title(): string { return 'Email summary'; }
    public function group(): string { return 'Communication'; }
    public function description(): string { return 'Emails received per category in the period: still open, closed, corrected by hand.'; }
    public function permission(): string { return 'mail.view'; }
    public function filters(): array { return ['period', 'branch', 'employee']; }

    public function columns(): array
    {
        return ['category' => ['Category', 'text'], 'received' => ['Received', 'int'], 'open' => ['Not closed', 'int'],
                'closed' => ['Closed / ignored', 'int'], 'corrected' => ['Category corrected', 'int'], 'leads' => ['Leads created', 'int']];
    }

    public function rows(ReportFilters $f): array
    {
        $base = ['from' => $f->from, 'to' => $f->to, 'branch' => $f->branchId, 'employee' => $f->employeeId];
        [$w, $p] = MailQuery::where($f->user, $base);
        return array_map(static fn ($r) => ['category' => $r['name'], 'received' => (int) $r['received'], 'open' => (int) $r['open'],
            'closed' => (int) $r['closed'], 'corrected' => (int) $r['corrected'], 'leads' => (int) $r['leads']], Database::fetchAll(
            "SELECT c.name, COUNT(m.id) AS received, COALESCE(SUM(m.status IN ('new','open','in_progress')), 0) AS open,
                    COALESCE(SUM(m.status IN ('closed','ignored')), 0) AS closed, COALESCE(SUM(m.classification_method = 'manual'), 0) AS corrected,
                    COALESCE(SUM(m.lead_id IS NOT NULL), 0) AS leads
             FROM email_categories c LEFT JOIN email_messages m ON m.category_id = c.id AND {$w}
             WHERE c.status = 'active' GROUP BY c.id, c.name, c.sort_order ORDER BY c.sort_order",
            $p
        ));
    }
}
