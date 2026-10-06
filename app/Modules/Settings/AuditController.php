<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\Audit;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Imports\Importer;

/** Read-only audit trail (audit.view). The log itself can never be edited or deleted from the app. */
final class AuditController
{
    private const PER_PAGE = 50;

    public static function index(): void
    {
        [$f, $w, $p] = self::filters();
        $total = (int) Database::value("SELECT COUNT(*) FROM audit_logs a WHERE {$w}", $p);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
        Response::view('settings/audit', [
            'title'   => 'Audit log',
            'flash'   => Session::takeFlash(),
            'filters' => $f,
            'rows'    => Database::fetchAll("SELECT a.* FROM audit_logs a WHERE {$w} ORDER BY a.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'modules' => array_column(Database::fetchAll('SELECT DISTINCT module FROM audit_logs ORDER BY module'), 'module'),
            'users'   => Database::fetchAll('SELECT DISTINCT user_id, user_name FROM audit_logs WHERE user_id IS NOT NULL ORDER BY user_name'),
        ]);
    }

    public static function export(): void
    {
        [$f, $w, $p] = self::filters();
        $rows = Database::fetchAll("SELECT a.* FROM audit_logs a WHERE {$w} ORDER BY a.id DESC LIMIT 50000", $p);
        Audit::log('report.exported', 'audit', null, null, ['report' => 'audit_log', 'rows' => count($rows), 'filters' => array_filter($f)]);
        Csv::download('audit_log_' . date('Y-m-d') . '.csv', ['Time', 'User', 'Role', 'IP', 'Action', 'Module', 'Record', 'Before', 'After'],
            array_map(static fn ($r) => [date('d-m-Y H:i:s', strtotime($r['created_at'])), $r['user_name'] ?? 'system', $r['role_slug'] ?? '',
                $r['ip_address'] ?? '', $r['action'], $r['module'], $r['record_id'] ?? '', $r['old_data'] ?? '', $r['new_data'] ?? ''], $rows));
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: list<mixed>} */
    private static function filters(): array
    {
        $f = [
            'from'   => Importer::parseDate((string) ($_GET['from'] ?? '')) ?? '',
            'to'     => Importer::parseDate((string) ($_GET['to'] ?? '')) ?? '',
            'user'   => (int) ($_GET['user'] ?? 0) ?: null,
            'module' => preg_match('/^[a-z_]{1,50}$/', (string) ($_GET['module'] ?? '')) ? $_GET['module'] : '',
            'action' => mb_substr(trim((string) ($_GET['action'] ?? '')), 0, 60),
            'record' => ctype_digit((string) ($_GET['record'] ?? '')) ? (int) $_GET['record'] : null,
        ];
        $w = ['1 = 1'];
        $p = [];
        if ($f['from'] !== '') { $w[] = 'a.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if ($f['to'] !== '') { $w[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $f['to']; }
        if ($f['user'] !== null) { $w[] = 'a.user_id = ?'; $p[] = $f['user']; }
        if ($f['module'] !== '') { $w[] = 'a.module = ?'; $p[] = $f['module']; }
        if ($f['action'] !== '') { $w[] = 'a.action LIKE ?'; $p[] = '%' . addcslashes($f['action'], '%_\\') . '%'; }
        if ($f['record'] !== null) { $w[] = 'a.record_id = ?'; $p[] = $f['record']; }
        return [$f, implode(' AND ', $w), $p];
    }
}
