<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Core\Database;
use App\Core\Gate;
use App\Modules\Reports\Report;
use App\Modules\Reports\ReportFilters;

final class SmsUsage extends Report
{
    public function key(): string { return 'sms-usage'; }
    public function title(): string { return 'SMS usage'; }
    public function group(): string { return 'Communication'; }
    public function description(): string { return 'Messages and billable SMS parts per day in the period.'; }
    public function permission(): string { return 'sms.view'; }
    public function filters(): array { return ['period']; }

    public function columns(): array
    {
        return ['day' => ['Date', 'date'], 'messages' => ['Messages', 'int'], 'delivered' => ['Sent / delivered', 'int'], 'failed' => ['Failed', 'int'],
                'cancelled' => ['Cancelled', 'int'], 'parts' => ['SMS parts sent', 'int'], 'test' => ['Test (not real)', 'int']];
    }

    public function rows(ReportFilters $f): array
    {
        $own = Gate::isSuper($f->user) || $f->scope->isUnrestricted() ? '' : ' AND s.created_by = ' . (int) $f->user['id'];
        return array_map(static fn ($r) => array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $r), Database::fetchAll(
            "SELECT DATE(s.created_at) AS day, COUNT(*) AS messages, SUM(s.status IN ('sent','delivered')) AS delivered, SUM(s.status = 'failed') AS failed,
                    SUM(s.status = 'cancelled') AS cancelled, SUM(IF(s.status IN ('sent','delivered'), s.segments, 0)) AS parts, SUM(s.is_test) AS test
             FROM sms_messages s WHERE s.created_at >= ? AND s.created_at < DATE_ADD(?, INTERVAL 1 DAY){$own}
             GROUP BY DATE(s.created_at) ORDER BY day",
            [$f->from . ' 00:00:00', $f->to]
        ));
    }
}
