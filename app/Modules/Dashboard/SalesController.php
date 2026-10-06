<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Modules\Dashboard\Kpi\SalesKpi;

/** Sales Performance drill-down (web), CSV export and JSON for the mobile app. */
final class SalesController
{
    /** window key => [label, DashboardContext window name] */
    public const WINDOWS = [
        'fy'    => ['FY to date', 'fy_to_date'],
        'prev'  => ['FY to previous day', 'fy_to_previous_day'],
        'month' => ['Month to previous day', 'month_to_previous_day'],
        'today' => ['As-on day', 'today'],
    ];
    private const PER_PAGE = 50;

    public static function detail(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new SalesKpi($ctx);

        $window = array_key_exists($_GET['w'] ?? '', self::WINDOWS) ? $_GET['w'] : 'fy';
        $range = $ctx->windows()[self::WINDOWS[$window][1]];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $docs = $range ? $kpi->documents($range, self::PER_PAGE, ($page - 1) * self::PER_PAGE) : ['rows' => [], 'total' => 0, 'count' => 0];

        Response::view('dashboard/sales', [
            'title'     => 'Sales Performance · Dashboard',
            'ctx'       => $ctx,
            's'         => $kpi->summary(),
            'monthly'   => $kpi->monthly(),
            'byBranch'  => $kpi->breakdown('branch'),
            'byEmployee'=> $kpi->breakdown('employee'),
            'window'    => $window,
            'range'     => $range,
            'docs'      => $docs,
            'page'      => $page,
            'pages'     => max(1, (int) ceil($docs['count'] / self::PER_PAGE)),
            'canExport' => Gate::allows('sales.export'),
        ]);
    }

    /** CSV of every document in the chosen window (same rows as the on-screen total). */
    public static function export(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new SalesKpi($ctx);
        $window = array_key_exists($_GET['w'] ?? '', self::WINDOWS) ? $_GET['w'] : 'fy';
        $range = $ctx->windows()[self::WINDOWS[$window][1]];
        if ($range === null) {
            Response::error(404, 'There are no days in this period yet.');
            return;
        }

        $docs = $kpi->documents($range, 100000, 0);
        Audit::log('report.exported', 'sales', null, null, [
            'report' => 'sales_performance', 'window' => $range->label(), 'filters' => $ctx->filters, 'rows' => $docs['count'],
        ]);

        $file = 'sales_' . $window . '_' . $ctx->asOn->format('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows ₹ / Tamil names correctly
        fputcsv($out, ['Date', 'Document', 'Type', 'Customer code', 'Customer', 'Branch', 'Sales employee', 'Taxable value', 'Total incl. GST']);
        foreach ($docs['rows'] as $r) {
            fputcsv($out, array_map([self::class, 'csvSafe'], [
                date('d-m-Y', strtotime($r['invoice_date'])), $r['invoice_no'],
                $r['document_type'] === 'credit_note' ? 'Credit note' : 'Invoice',
                $r['customer_code'], $r['customer'], $r['branch_code'], $r['employee'] ?? '',
                $r['taxable_value'], $r['total_value'],
            ]));
        }
        fputcsv($out, ['', '', '', '', 'TOTAL (' . ($kpi->basisColumn() === 'total_value' ? 'incl. GST' : 'taxable') . ')', '', '',
            $kpi->basisColumn() === 'taxable_value' ? Money::toDecimal($docs['total']) : '',
            $kpi->basisColumn() === 'total_value' ? Money::toDecimal($docs['total']) : '']);
        fclose($out);
    }

    /** GET /api/dashboard/sales - same figures for the mobile app (amounts as decimal strings). */
    public static function api(): void
    {
        $ctx = DashboardContext::fromRequest(Auth::user(), $_GET);
        $kpi = new SalesKpi($ctx);
        $s = $kpi->summary();
        foreach ($s as $k => $v) {
            if (is_int($v) && !in_array($k, ['completed_months', 'remaining_months', 'documents_today', 'documents_fy', 'customers_fy'], true)) {
                $s[$k] = Money::toDecimal($v);
            }
        }
        $monthly = array_map(static fn (array $m): array => [
            'month'  => $m['month'],
            'target' => $m['target'] === null ? null : Money::toDecimal($m['target']),
            'sales'  => $m['sales'] === null ? null : Money::toDecimal($m['sales']),
        ], $kpi->monthly());

        Response::json(['success' => true, 'data' => ['summary' => $s, 'monthly' => $monthly, 'filters' => $ctx->filters, 'notices' => $ctx->notices]]);
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ at the start of a cell). */
    public static function csvSafe(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        if ($s !== '' && $s[0] === '-' && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }
}
