<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Modules\Dashboard\Kpi\CollectionKpi;
use App\Modules\Dashboard\Kpi\OpenBills;

/** Step A2 - Payment Collection drill-down, CSV export, mobile API. */
final class CollectionController
{
    public const WINDOWS = [
        'month' => ['Month to date', 'month_to_date'],
        'prev'  => ['Previous period', 'month_to_previous_day'],
        'today' => ['As-on day', 'today'],
        'fy'    => ['FY to date', 'fy_to_date'],
    ];
    private const PER_PAGE = 50;

    public static function detail(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new CollectionKpi($ctx);
        $window = array_key_exists($_GET['w'] ?? '', self::WINDOWS) ? $_GET['w'] : 'month';
        $range = $ctx->windows()[self::WINDOWS[$window][1]];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $kpi->applies() && $range ? $kpi->receipts($range, self::PER_PAGE, ($page - 1) * self::PER_PAGE) : ['rows' => [], 'total' => 0, 'count' => 0];

        Response::view('dashboard/collection', [
            'title'      => 'Payment Collection · Dashboard',
            'ctx'        => $ctx,
            'c'          => $kpi->summary(),
            'monthly'    => $kpi->applies() ? $kpi->monthly() : [],
            'byBranch'   => $kpi->applies() ? $kpi->breakdown('branch') : [],
            'byEmployee' => $kpi->applies() ? $kpi->breakdown('employee') : [],
            'overdue'    => $kpi->applies() ? $kpi->overdueBills(20, 0) : ['rows' => [], 'total' => 0, 'count' => 0],
            'window'     => $window,
            'range'      => $range,
            'list'       => $list,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($list['count'] / self::PER_PAGE)),
            'canExport'  => Gate::allows('collections.export'),
            'billSource' => OpenBills::source(),
        ]);
    }

    public static function export(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new CollectionKpi($ctx);
        $window = array_key_exists($_GET['w'] ?? '', self::WINDOWS) ? $_GET['w'] : 'month';
        $range = $ctx->windows()[self::WINDOWS[$window][1]];
        if (!$kpi->applies() || $range === null) {
            Response::error(404, 'Nothing to export for this selection.');
            return;
        }
        $list = $kpi->receipts($range, 100000, 0);
        Audit::log('report.exported', 'collections', null, null, ['report' => 'payment_collection', 'window' => $range->label(), 'filters' => $ctx->filters, 'rows' => $list['count']]);

        $rows = array_map(static fn (array $r): array => [
            date('d-m-Y', strtotime($r['receipt_date'])), $r['receipt_no'], $r['customer_code'], $r['customer'], $r['branch_code'], $r['employee'] ?? '',
            strtoupper($r['payment_mode']), $r['reference_no'] ?? '', $r['status'], $r['amount'], $r['allocated_amount'], $r['unallocated_amount'],
        ], $list['rows']);
        $rows[] = ['', '', '', 'TOTAL', '', '', '', '', '', Money::toDecimal($list['total']), '', ''];

        Csv::download('collection_' . $window . '_' . $ctx->asOn->format('Y-m-d') . '.csv',
            ['Date', 'Receipt', 'Customer code', 'Customer', 'Branch', 'Sales employee', 'Mode', 'UTR / cheque', 'Status', 'Amount', 'Adjusted to bills', 'On account'],
            $rows);
    }

    public static function api(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new CollectionKpi($ctx);
        $s = $kpi->summary();
        foreach ($s as $k => $v) {
            if (is_int($v) && !in_array($k, ['customers', 'payments', 'payments_today', 'overdue_customers', 'overdue_bills'], true)) {
                $s[$k] = Money::toDecimal($v);
            }
        }
        Response::json(['success' => true, 'data' => ['summary' => $s, 'filters' => $ctx->filters, 'notices' => $ctx->notices]]);
    }
}
