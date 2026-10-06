<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\SampleDcKpi;
use DateTimeImmutable;

/** Sales Details: the combined target / sales / collection / pending / outstanding grid. */
final class SalesDetailsController
{
    /** column => [heading, permission] */
    public const COLUMNS = [
        'target'      => ['Target', 'targets.view'],
        'sales'       => ['Sales', 'sales.view'],
        'collection'  => ['Collection', 'collections.view'],
        'pending'     => ['Pending orders', 'pending_orders.view'],
        'samples'     => ['Samples', 'samples.view'],
        'dc'          => ['DC', 'dc.view'],
        'outstanding' => ['Outstanding', 'outstanding.view'],
        'd90'         => ['91-150 days', 'outstanding.view'],
        'd150'        => ['150+ days', 'outstanding.view'],
    ];
    private const PER_PAGE = 50;

    public static function index(): void
    {
        [$ctx, $by, $grid] = self::load();
        Response::view('sales/index', [
            'title'     => 'Sales Details',
            'ctx'       => $ctx,
            'by'        => $by,
            'grid'      => $grid,
            'fyOptions' => DashboardContext::financialYearOptions(new DateTimeImmutable('today')),
            'months'    => $ctx->monthOptions(new DateTimeImmutable('today')),
            'branches'  => $ctx->branchOptions(),
            'employees' => $ctx->employeeOptions(null),
            'canExport' => Gate::allows('sales.export'),
        ]);
    }

    public static function export(): void
    {
        [$ctx, $by, $grid] = self::load();
        Audit::log('report.exported', 'sales', null, null, ['report' => 'sales_details', 'by' => $by, 'filters' => $ctx->filters, 'rows' => count($grid['rows'])]);
        $heads = array_merge([SalesGrid::GROUPS[$by]], array_map(static fn ($c) => self::COLUMNS[$c][0], $grid['columns']));
        $hasPct = in_array('target', $grid['columns'], true) && in_array('sales', $grid['columns'], true);
        if ($hasPct) {
            $heads[] = 'Achieved %';
        }
        $line = static function (array $r) use ($grid, $hasPct): array {
            $out = [$r['label']];
            foreach ($grid['columns'] as $c) {
                $out[] = Money::toDecimal($r[$c]);
            }
            if ($hasPct) {
                $out[] = $r['achieved_pct'] ?? '';
            }
            return $out;
        };
        $rows = array_map($line, $grid['rows']);
        $rows[] = $line(['label' => 'TOTAL'] + $grid['totals']);
        Csv::download('sales_details_' . $by . '_' . $ctx->asOn->format('Y-m-d') . '.csv', $heads, $rows);
    }

    /** Drill-down for the pending samples / pending DC columns (the dashboard has no separate page for these). */
    public static function documents(array $p): void
    {
        $kind = $p['kind'] === 'dc' ? 'dc' : 'samples';
        if (!Gate::allows($kind === 'dc' ? 'dc.view' : 'samples.view')) {
            Response::error(403, 'You do not have permission to view this.');
            return;
        }
        $ctx = self::context();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $docs = (new SampleDcKpi($ctx))->documents($kind === 'dc' ? 'dc' : 'sample', self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        Response::view('sales/documents', [
            'title' => ($kind === 'dc' ? 'Pending DC' : 'Pending samples') . ' · Sales Details',
            'ctx'   => $ctx,
            'kind'  => $kind,
            'docs'  => $docs,
            'page'  => $page,
            'pages' => max(1, (int) ceil($docs['count'] / self::PER_PAGE)),
        ]);
    }

    /** JSON for the mobile app (same numbers as the web grid). */
    public static function api(): void
    {
        [$ctx, $by, $grid] = self::load();
        Response::json(['success' => true, 'data' => [
            'as_on'   => $ctx->asOn->format('Y-m-d'),
            'fy'      => $ctx->fyRow['label'],
            'by'      => $by,
            'columns' => array_map(static fn ($c) => ['key' => $c, 'label' => self::COLUMNS[$c][0]], $grid['columns']),
            'rows'    => self::rupeeRows($grid['rows'], $grid['columns']),
            'totals'  => self::rupeeRows([$grid['totals']], $grid['columns'])[0],
            'note'    => 'Amounts are in rupees (2 decimals).',
            'notices' => $ctx->notices,
        ]]);
    }

    // -------------------------------------------------------------------------

    /** @return array{0: DashboardContext, 1: string, 2: array<string, mixed>} */
    private static function load(): array
    {
        $ctx = self::context();
        $by = array_key_exists($_GET['by'] ?? '', SalesGrid::GROUPS) ? $_GET['by'] : 'employee';
        $allowed = [];
        foreach (self::COLUMNS as $col => [, $perm]) {
            if (Gate::allows($perm)) {
                $allowed[] = $col;
            }
        }
        return [$ctx, $by, (new SalesGrid($ctx, $allowed))->build($by)];
    }

    /** Only FY / month / branch / employee apply here (targets are per employee; no customer or product split). */
    private static function context(): DashboardContext
    {
        return DashboardContext::fromRequest(Auth::user(), array_intersect_key($_GET, array_flip(['fy', 'month', 'branch', 'employee'])));
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $columns @return list<array<string, mixed>> */
    private static function rupeeRows(array $rows, array $columns): array
    {
        return array_map(static function (array $r) use ($columns): array {
            $out = isset($r['key']) ? ['key' => $r['key'], 'label' => $r['label']] : [];
            foreach ($columns as $c) {
                $out[$c] = Money::toDecimal($r[$c]);
            }
            $out['achieved_pct'] = $r['achieved_pct'] ?? null;
            return $out;
        }, $rows);
    }
}
