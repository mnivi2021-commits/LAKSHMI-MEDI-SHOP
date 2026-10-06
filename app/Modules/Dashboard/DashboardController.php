<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Response;
use App\Core\Session;
use DateTimeImmutable;

final class DashboardController
{
    public static function index(): void
    {
        $user = Auth::user();
        if (!Gate::allows('dashboard.view', $user)) {
            Response::view('home', ['title' => 'Home · Marketing CRM', 'user' => $user, 'flash' => Session::takeFlash()]);
            return;
        }

        $today = new DateTimeImmutable('today');
        $ctx = DashboardContext::fromRequest($user, $_GET, $today);

        Response::view('dashboard/index', [
            'title'      => 'Dashboard · Marketing CRM',
            'flash'      => Session::takeFlash(),
            'ctx'        => $ctx,
            'windows'    => $ctx->windows(),
            'fyOptions'  => DashboardContext::financialYearOptions($today),
            'months'     => $ctx->monthOptions($today),
            'branches'   => $ctx->branchOptions(),
            'employees'  => $ctx->employeeOptions($ctx->filters['branch_id']),
            'addTypes'   => QuickAddService::allowedTypes($user),
            'freshness'  => self::freshness($ctx),
            'today'      => $today,
            'allFys'     => Database::fetchAll('SELECT id, label, start_date FROM financial_years WHERE is_locked = 0 ORDER BY start_date'),
            'currentFyId'=> (int) $ctx->fyRow['id'],
        ]);
    }

    /** POST /dashboard/add/{type} - JSON in, JSON out. */
    public static function quickAdd(array $p): void
    {
        [$status, $body] = (new QuickAddService(Auth::user()))->handle($p['type'], $_POST);
        Response::json($body, $status);
    }

    /** GET /dashboard/lookup/{type}?q=  - scope-limited search for the ADD panel and filters. */
    public static function lookup(array $p): void
    {
        $user = Auth::user();
        $ctx = DashboardContext::fromRequest($user, []);
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        $like = '%' . addcslashes($q, '%_\\') . '%';

        if ($p['type'] === 'customers') {
            [$scopeSql, $params] = $ctx->scope->where('c.branch_id', 'c.employee_id');
            $rows = Database::fetchAll(
                "SELECT c.id, c.customer_code AS code, c.name, c.city, b.branch_code, e.short_name AS employee
                 FROM customers c
                 JOIN branches b ON b.id = c.branch_id
                 LEFT JOIN employees e ON e.id = c.employee_id
                 WHERE c.deleted_at IS NULL AND c.status <> 'blocked' AND {$scopeSql}
                   AND (c.name LIKE ? OR c.customer_code LIKE ? OR c.company_name LIKE ? OR c.mobile LIKE ?)
                 ORDER BY c.name LIMIT 20",
                array_merge($params, [$like, $like, $like, $like])
            );
            $items = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'label' => $r['name'],
                'meta' => trim($r['code'] . ' · ' . ($r['city'] ?? '') . ' · ' . $r['branch_code'] . ($r['employee'] ? ' · ' . $r['employee'] : ''), ' ·'),
            ], $rows);
        } elseif ($p['type'] === 'products') {
            $rows = Database::fetchAll(
                "SELECT id, product_code AS code, name, unit, rate, gst_rate FROM products
                 WHERE deleted_at IS NULL AND status = 'active' AND (name LIKE ? OR product_code LIKE ?)
                 ORDER BY name LIMIT 20",
                [$like, $like]
            );
            $items = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'label' => $r['name'],
                'meta' => $r['code'] . ' · ' . $r['unit'] . ' · GST ' . rtrim(rtrim((string) $r['gst_rate'], '0'), '.') . '%',
                'gst' => rtrim(rtrim((string) $r['gst_rate'], '0'), '.') ?: '0',
            ], $rows);
        } else {
            Response::json(['success' => false, 'message' => 'Unknown lookup.'], 404);
            return;
        }

        Response::json(['success' => true, 'items' => $items]);
    }

    /**
     * Latest transaction dates in the user's scope, so management can see how
     * current the data is before trusting a number.
     *
     * @return array<string, ?string>
     */
    private static function freshness(DashboardContext $ctx): array
    {
        $out = [];
        $sources = [
            'Sales'         => ['sales_invoices', 'invoice_date'],
            'Collection'    => ['collections', 'receipt_date'],
            'Pending order' => ['pending_orders', 'order_date'],
            'Sample'        => ['samples', 'document_date'],
            'DC'            => ['dc_records', 'dc_date'],
        ];
        [$sql, $params] = $ctx->scope->where('t.branch_id', 't.employee_id');
        foreach ($sources as $label => [$table, $col]) {
            $out[$label] = Database::value("SELECT MAX(t.{$col}) FROM {$table} t WHERE t.deleted_at IS NULL AND {$sql}", $params);
        }
        return $out;
    }
}
