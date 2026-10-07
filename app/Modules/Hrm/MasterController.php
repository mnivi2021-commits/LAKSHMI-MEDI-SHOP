<?php

declare(strict_types=1);

namespace App\Modules\Hrm;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** HRM lookup lists: departments and designations (add, rename, activate / deactivate). */
final class MasterController
{
    private const TYPES = [
        'departments'  => ['label' => 'Departments', 'singular' => 'Department', 'fk' => 'department_id'],
        'designations' => ['label' => 'Designations', 'singular' => 'Designation', 'fk' => 'designation_id'],
        'divisions'    => ['label' => 'Divisions', 'singular' => 'Division', 'fk' => null],
        'sales_areas'  => ['label' => 'Sales areas', 'singular' => 'Sales area', 'fk' => null],
    ];

    public static function index(array $p): void
    {
        $type = self::type($p);
        if ($type === null) {
            return;
        }
        $def = self::TYPES[$type];
        $rows = Database::fetchAll(
            "SELECT t.id, t.name, t.status, " . ($type === 'sales_areas' ? '(SELECT b.name FROM branches b WHERE b.id = t.branch_id) AS branch_name, t.branch_id, ' : '') . match (true) {
                $def['fk'] !== null => "(SELECT COUNT(*) FROM employees e WHERE e.{$def['fk']} = t.id AND e.deleted_at IS NULL)",
                $type === 'sales_areas' => '(SELECT COUNT(*) FROM employees e WHERE e.area = t.name AND e.deleted_at IS NULL)',
                default => '(SELECT COUNT(DISTINCT a.employee_id) FROM annual_targets a WHERE a.division_id = t.id)',
            } . " AS employees
             FROM {$type} t ORDER BY t.status = 'active' DESC, t.name"
        );
        Response::view('hrm/masters', [
            'branches' => $type === 'sales_areas' ? Database::fetchAll("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY name") : [],
            'title'   => $def['label'] . ' · HRM',
            'flash'   => Session::takeFlash(),
            'type'    => $type,
            'def'     => $def,
            'rows'    => $rows,
            'canEdit' => Gate::allows('hrm.edit'),
        ]);
    }

    public static function store(array $p): void
    {
        $type = self::type($p);
        if ($type === null) {
            return;
        }
        $name = Request::input('name', 80);
        if (($error = self::nameError($type, $name, 0)) !== null) {
            Session::flash('error', $error);
        } else {
            Database::query("INSERT INTO {$type} (name, created_by, updated_by) VALUES (?, ?, ?)", [$name, Auth::id(), Auth::id()]);
            if ($type === 'sales_areas') {
                self::setBranch((int) Database::connection()->lastInsertId());
            }
            Audit::log(rtrim($type, 's') . '.created', 'hrm', (int) Database::connection()->lastInsertId(), null, ['name' => $name]);
            Session::flash('success', self::TYPES[$type]['singular'] . " \"{$name}\" added.");
        }
        Response::redirect("/hrm/lists/{$type}");
    }

    public static function update(array $p): void
    {
        $type = self::type($p);
        if ($type === null) {
            return;
        }
        $row = Database::fetch("SELECT * FROM {$type} WHERE id = ?", [(int) $p['id']]);
        if ($row === null) {
            Response::error(404, 'Not found.');
            return;
        }
        $action = Request::input('action', 10);
        if ($action === 'toggle') {
            $new = $row['status'] === 'active' ? 'inactive' : 'active';
            Database::query("UPDATE {$type} SET status = ?, updated_by = ? WHERE id = ?", [$new, Auth::id(), $row['id']]);
            Audit::log(rtrim($type, 's') . '.status_changed', 'hrm', (int) $row['id'], ['status' => $row['status']], ['status' => $new]);
            Session::flash('success', "\"{$row['name']}\" is now {$new}" . ($new === 'inactive' ? ' and will not be offered for new employees.' : '.'));
        } else {
            $name = Request::input('name', 80);
            if (($error = self::nameError($type, $name, (int) $row['id'])) !== null) {
                Session::flash('error', $error);
            } elseif ($type === 'sales_areas' && (int) Request::input('branch_id', 10) !== (int) ($row['branch_id'] ?? 0)) {
                if ($name !== $row['name']) {
                    Database::query("UPDATE {$type} SET name = ?, updated_by = ? WHERE id = ?", [$name, Auth::id(), $row['id']]);
                }
                self::setBranch((int) $row['id']);
                Session::flash('success', "\"{$name}\" saved.");
            } elseif ($name !== $row['name']) {
                Database::query("UPDATE {$type} SET name = ?, updated_by = ? WHERE id = ?", [$name, Auth::id(), $row['id']]);
                Audit::log(rtrim($type, 's') . '.renamed', 'hrm', (int) $row['id'], ['name' => $row['name']], ['name' => $name]);
                Session::flash('success', "Renamed to \"{$name}\".");
            }
        }
        Response::redirect("/hrm/lists/{$type}");
    }

    /** Sales areas: store the branch chosen on the form (blank = no branch). */
    private static function setBranch(int $id): void
    {
        $b = (int) Request::input('branch_id', 10) ?: null;
        if ($b !== null && !Database::value('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL', [$b])) {
            $b = null;
        }
        Database::query('UPDATE sales_areas SET branch_id = ?, updated_by = ? WHERE id = ?', [$b, Auth::id(), $id]);
    }

    private static function type(array $p): ?string
    {
        $type = $p['type'] ?? '';
        if (!isset(self::TYPES[$type])) {
            Response::error(404, 'Not found.');
            return null;
        }
        return $type;
    }

    private static function nameError(string $type, string $name, int $excludeId): ?string
    {
        if ($name === '') {
            return 'Enter a name.';
        }
        if (mb_strlen($name) > 80) {
            return 'Name must be at most 80 characters.';
        }
        if (Database::value("SELECT 1 FROM {$type} WHERE name = ? AND id <> ?", [$name, $excludeId])) {
            return "\"{$name}\" already exists.";
        }
        return null;
    }
}
