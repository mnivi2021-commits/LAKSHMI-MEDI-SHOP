<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Core\Spreadsheet\XlsxWriter;
use DateTimeImmutable;

/** Report centre: list, view with filters, CSV / Excel export. */
final class ReportController
{
    /** In menu order. */
    public const REPORTS = [
        Reports\SalesDetails::class, Reports\TargetCommitment::class, Reports\PendingOrders::class, Reports\PendingSamplesDc::class,
        Reports\PaymentPending::class,
        Reports\SalesRegister::class, Reports\SalesByCustomer::class, Reports\SalesByProduct::class, Reports\TargetAchievement::class,
        Reports\CollectionRegister::class, Reports\OutstandingAgeing::class,
        Reports\LeadConversion::class, Reports\FollowupsDue::class,
        Reports\SmsUsage::class, Reports\EmailSummary::class,
    ];
    public const SCREEN_LIMIT = 1000;

    /** @return array<string, Report> reports this user may open */
    public static function available(array $user): array
    {
        if (!Gate::allows('reports.view', $user)) {
            return [];
        }
        $out = [];
        foreach (self::REPORTS as $class) {
            $r = new $class();
            if (Gate::allows($r->permission(), $user)) {
                $out[$r->key()] = $r;
            }
        }
        return $out;
    }

    public static function index(): void
    {
        $groups = [];
        foreach (self::available(Auth::user()) as $r) {
            $groups[$r->group()][] = $r;
        }
        $user = Auth::user();
        $scope = DataScope::for($user);
        [$bw, $bp] = $scope->branchListWhere('id');
        [$ew, $ep] = $scope->where('branch_id', 'id');
        $branch = (int) ($_GET['branch'] ?? 0) ?: null;
        $branch = $branch !== null && $scope->allowsBranch($branch) ? $branch : null;
        $employee = (int) ($_GET['employee'] ?? 0) ?: null;
        $employee = $employee !== null && $scope->allowsEmployee($employee) ? $employee : null;
        $employees = Database::fetchAll(
            "SELECT id, name, short_name FROM employees WHERE deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1 AND {$ew}"
            . ($branch !== null ? ' AND branch_id = ?' : '') . ' ORDER BY COALESCE(short_name, name)',
            $branch !== null ? array_merge($ep, [$branch]) : $ep);
        Response::view('reports/index', [
            'title'     => 'Reports',
            'groups'    => $groups,
            'canExport' => Gate::allows('reports.export'),
            'branches'  => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND {$bw} ORDER BY name", $bp),
            'employees' => $employees,
            'branch'    => $branch,
            'employee'  => $employee,
        ]);
    }

    public static function show(array $p): void
    {
        $user = Auth::user();
        $report = self::available($user)[$p['key'] ?? ''] ?? null;
        if ($report === null) {
            Response::error(404, 'Report not found (or you do not have access to it).');
            return;
        }
        $f = new ReportFilters($user, $_GET, new DateTimeImmutable('today'));
        $rows = $report->rows($f);
        $scope = DataScope::for($user);
        [$bw, $bp] = $scope->branchListWhere('id');
        [$ew, $ep] = $scope->where('branch_id', 'id');
        Response::view('reports/show', [
            'title'     => $report->title() . ' · Reports',
            'report'    => $report,
            'f'         => $f,
            'rows'      => array_slice($rows, 0, self::SCREEN_LIMIT),
            'count'     => count($rows),
            'totals'    => $report->totals($rows),
            'branches'  => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND {$bw} ORDER BY name", $bp),
            'employees' => Database::fetchAll("SELECT id, name, short_name FROM employees WHERE deleted_at IS NULL AND {$ew} ORDER BY name", $ep),
            'canExport' => Gate::allows('reports.export'),
        ]);
    }

    public static function export(array $p): void
    {
        $user = Auth::user();
        $report = self::available($user)[$p['key'] ?? ''] ?? null;
        if ($report === null) {
            Response::error(404, 'Report not found (or you do not have access to it).');
            return;
        }
        $format = ($p['format'] ?? '') === 'xlsx' ? 'xlsx' : 'csv';
        $f = new ReportFilters($user, $_GET, new DateTimeImmutable('today'));
        $rows = $report->rows($f);
        $cols = $report->columns();
        $header = array_map(static fn ($c) => $c[0], array_values($cols));
        $line = static function (array $r) use ($cols): array {
            $out = [];
            foreach ($cols as $k => [, $type]) {
                $v = $r[$k] ?? null;
                $out[] = match (true) {
                    $v === null || $v === ''  => '',
                    $type === 'money'          => Money::toDecimal((int) $v),
                    $type === 'date'           => date('d-m-Y', strtotime((string) $v)),
                    default                    => (string) $v,
                };
            }
            return $out;
        };
        $data = array_map($line, $rows);
        $totals = $report->totals($rows);
        if ($totals !== []) {
            $t = $line($totals);
            $t[0] = 'TOTAL';
            $data[] = $t;
        }
        Audit::log('report.exported', 'reports', null, null, ['report' => $report->key(), 'format' => $format, 'rows' => count($rows), 'filters' => $f->query()]);
        $name = $report->key() . '_' . date('Y-m-d');
        if ($format === 'csv') {
            Csv::download($name . '.csv', $header, $data);
            return;
        }
        $formats = [];
        foreach (array_values($cols) as $i => [, $type]) {
            if ($type === 'money') {
                $formats[$i] = 'money';
            } elseif (in_array($type, ['int', 'qty', 'pct'], true)) {
                $formats[$i] = 'number';
            }
        }
        $period = in_array('period', $report->filters(), true) ? date('d-m-Y', strtotime($f->from)) . ' to ' . date('d-m-Y', strtotime($f->to)) : 'as on ' . date('d-m-Y', strtotime($f->asOn));
        $bytes = (new XlsxWriter())
            ->addSheet($report->title(), array_merge([$header], $data), $formats)
            ->addSheet('About', [['Report', $report->title()], ['Period', $period], ['Generated', date('d-m-Y H:i') . ' by ' . $user['name']],
                                 ['Rows', (string) count($rows)], ['Note', (string) $report->note($f)]])
            ->toString();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }
}
