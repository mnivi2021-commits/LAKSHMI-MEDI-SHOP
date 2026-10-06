<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Modules\Dashboard\Kpi\Aging;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;

/** Step A3 - Branch Pending Order drill-down, CSV export, mobile API. */
final class PendingOrderController
{
    private const PER_PAGE = 50;

    public static function detail(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new PendingOrderKpi($ctx);
        $bucket = Aging::find($_GET['bucket'] ?? null) ? $_GET['bucket'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $lines = $kpi->lines($bucket, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        Response::view('dashboard/pending', [
            'title'      => 'Branch Pending Order · Dashboard',
            'ctx'        => $ctx,
            'p'          => $kpi->summary(),
            'byBranch'   => $kpi->breakdown('branch'),
            'byEmployee' => $kpi->breakdown('employee'),
            'bucket'     => $bucket,
            'lines'      => $lines,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($lines['count'] / self::PER_PAGE)),
            'canExport'  => Gate::allows('pending_orders.export'),
        ]);
    }

    public static function export(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $bucket = Aging::find($_GET['bucket'] ?? null) ? $_GET['bucket'] : null;
        $lines = (new PendingOrderKpi($ctx))->lines($bucket, 100000, 0);
        Audit::log('report.exported', 'pending_orders', null, null, ['report' => 'pending_orders', 'bucket' => $bucket, 'filters' => $ctx->filters, 'rows' => $lines['count']]);

        $rows = array_map(static fn (array $r): array => [
            $r['order_no'], date('d-m-Y', strtotime($r['order_date'])), $r['age'], $r['customer_po_no'] ?? '', $r['customer_code'], $r['customer'],
            $r['branch_code'], $r['employee'] ?? '', $r['product'], $r['order_qty'], $r['supplied_qty'], $r['pending_qty'],
            $r['order_value'], $r['supplied_value'], $r['pending_value'],
            $r['expected_delivery_date'] ? date('d-m-Y', strtotime($r['expected_delivery_date'])) : '',
        ], $lines['rows']);
        $rows[] = ['TOTAL', '', '', '', '', '', '', '', '', '', '', '', '', '', Money::toDecimal($lines['total']), ''];

        Csv::download('pending_orders_' . ($bucket ?? 'all') . '_' . $ctx->asOn->format('Y-m-d') . '.csv',
            ['Order', 'Order date', 'Age (days)', 'Customer PO', 'Customer code', 'Customer', 'Branch', 'Sales employee', 'Product',
             'Ordered qty', 'Supplied qty', 'Pending qty', 'Order value', 'Supplied value', 'Pending value', 'Expected delivery'],
            $rows);
    }

    public static function api(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $s = (new PendingOrderKpi($ctx))->summary();
        foreach (['value', 'current_value'] as $k) {
            $s[$k] = Money::toDecimal($s[$k]);
        }
        if ($s['oldest']) {
            $s['oldest']['value'] = Money::toDecimal($s['oldest']['value']);
        }
        foreach ($s['aging'] as &$a) {
            $a['value'] = Money::toDecimal($a['value']);
        }
        unset($a);
        Response::json(['success' => true, 'data' => ['summary' => $s, 'filters' => $ctx->filters, 'notices' => $ctx->notices]]);
    }
}
