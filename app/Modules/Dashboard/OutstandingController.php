<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Modules\Dashboard\Kpi\OpenBills;
use App\Modules\Dashboard\Kpi\OutstandingKpi;

/**
 * 90 / 150 Day Outstanding drill-down, CSV export, mobile API.
 *
 * "90 DAYS" = 91-150 days old, "150 DAYS" = over 150 days (see docs/DATABASE.md
 * section 4 - confirmed default; change OutstandingKpi::CATEGORIES to relabel).
 */
final class OutstandingController
{
    private const PER_PAGE = 50;

    public static function detail(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new OutstandingKpi($ctx);
        $cat = array_key_exists($_GET['cat'] ?? '', OutstandingKpi::CATEGORIES) ? $_GET['cat'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $bills = $kpi->bills($cat, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        Response::view('dashboard/outstanding', [
            'title'      => 'Outstanding · Dashboard',
            'ctx'        => $ctx,
            'categories' => $kpi->categories(),
            'total'      => $kpi->total(),
            'applies'    => $kpi->applies(),
            'cat'        => $cat,
            'customers'  => $kpi->customers($cat),
            'bills'      => $bills,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($bills['count'] / self::PER_PAGE)),
            'canExport'  => Gate::allows('outstanding.export'),
            'billSource' => OpenBills::source(),
            'ageBasis'   => OpenBills::ageBasisLabel(),
        ]);
    }

    public static function export(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new OutstandingKpi($ctx);
        $cat = array_key_exists($_GET['cat'] ?? '', OutstandingKpi::CATEGORIES) ? $_GET['cat'] : null;
        $bills = $kpi->bills($cat, 100000, 0);
        Audit::log('report.exported', 'outstanding', null, null, ['report' => 'outstanding_aging', 'category' => $cat, 'filters' => $ctx->filters, 'rows' => $bills['count']]);

        $rows = array_map(static fn (array $r): array => [
            $r['invoice_no'], date('d-m-Y', strtotime($r['invoice_date'])), date('d-m-Y', strtotime($r['due_date'])), $r['age'],
            $r['customer_code'], $r['customer'], $r['customer_mobile'] ?? '', $r['branch_code'], $r['employee'] ?? '',
            $r['bill_amount'], $r['balance'],
        ], $bills['rows']);
        $rows[] = ['TOTAL', '', '', '', '', '', '', '', '', '', Money::toDecimal($bills['total'])];

        Csv::download('outstanding_' . ($cat ?? 'all') . '_' . $ctx->asOn->format('Y-m-d') . '.csv',
            ['Invoice', 'Invoice date', 'Due date', 'Age (days)', 'Customer code', 'Customer', 'Mobile', 'Branch', 'Sales employee', 'Bill amount', 'Balance'],
            $rows);
    }

    public static function api(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new OutstandingKpi($ctx);
        $cats = $kpi->categories();
        foreach ($cats as &$c) {
            $c['value'] = Money::toDecimal($c['value']);
        }
        unset($c);
        Response::json(['success' => true, 'data' => [
            'applies'    => $kpi->applies(),
            'total'      => Money::toDecimal($kpi->total()),
            'categories' => $cats,
            'filters'    => $ctx->filters,
            'notices'    => $ctx->notices,
        ]]);
    }
}
