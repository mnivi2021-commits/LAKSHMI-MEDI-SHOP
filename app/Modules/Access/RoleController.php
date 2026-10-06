<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/** Roles and the module x action permission matrix (requires access.manage). */
final class RoleController
{
    private const SCOPES = ['all', 'branch', 'team', 'own'];

    public static function index(): void
    {
        $roles = Database::fetchAll(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS user_count,
                    IF(r.slug = ?, (SELECT COUNT(*) FROM permissions),
                       (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id)) AS permission_count
             FROM roles r ORDER BY r.is_system DESC, r.id",
            [Gate::SUPER_ROLE]
        );
        Response::view('access/roles/index', [
            'title'      => 'Roles & permissions · Access',
            'flash'      => Session::takeFlash(),
            'roles'      => $roles,
            'totalPerms' => (int) Database::value('SELECT COUNT(*) FROM permissions'),
        ]);
    }

    public static function create(): void
    {
        self::form(null);
    }

    public static function edit(array $p): void
    {
        $role = self::find((int) $p['id']);
        if ($role !== null) {
            self::form($role);
        }
    }

    public static function store(): void
    {
        [$data, $perms, $errors] = self::validated(null);
        if ($errors) {
            self::back('/access/roles/new', $errors, $perms);
            return;
        }

        $id = Database::transaction(static function () use ($data, $perms): int {
            Database::query(
                'INSERT INTO roles (name, slug, description, data_scope, is_system, status, created_by, updated_by) VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
                [$data['name'], $data['slug'], $data['description'], $data['data_scope'], 'active', Auth::id(), Auth::id()]
            );
            $id = (int) Database::connection()->lastInsertId();
            self::savePermissions($id, array_keys($perms));
            return $id;
        });

        Audit::log('role.created', 'access', $id, null, $data + ['permissions' => array_values($perms)]);
        Session::flash('success', "Role \"{$data['name']}\" created with " . count($perms) . ' permission(s).');
        Response::redirect('/access/roles');
    }

    public static function update(array $p): void
    {
        $role = self::find((int) $p['id']);
        if ($role === null) {
            return;
        }
        if ($role['slug'] === Gate::SUPER_ROLE) {
            Session::flash('error', 'The Admin Head role always has full access and cannot be changed.');
            Response::redirect('/access/roles');
            return;
        }

        [$data, $perms, $errors] = self::validated($role);
        if ($errors) {
            self::back("/access/roles/{$role['id']}", $errors, $perms);
            return;
        }

        $oldPerms = Gate::rolePermissions((int) $role['id']);
        $newPerms = array_values($perms);
        sort($newPerms);
        $added = array_values(array_diff($newPerms, $oldPerms));
        $removed = array_values(array_diff($oldPerms, $newPerms));

        // You may only add or remove permissions you hold yourself.
        if (!Gate::canGrantAll(array_merge($added, $removed))) {
            self::back("/access/roles/{$role['id']}", ['permissions' => 'You can only change permissions that you hold yourself.'], $perms);
            return;
        }

        Database::transaction(static function () use ($role, $data, $perms): void {
            Database::query(
                'UPDATE roles SET name = ?, description = ?, data_scope = ?, updated_by = ? WHERE id = ?',
                [$data['name'], $data['description'], $data['data_scope'], Auth::id(), $role['id']]
            );
            self::savePermissions((int) $role['id'], array_keys($perms));
        });

        Gate::forget();
        $changes = array_diff_assoc(
            ['name' => $data['name'], 'description' => $data['description'], 'data_scope' => $data['data_scope']],
            ['name' => $role['name'], 'description' => $role['description'], 'data_scope' => $role['data_scope']]
        );
        if ($changes) {
            Audit::log('role.updated', 'access', (int) $role['id'],
                array_intersect_key(['name' => $role['name'], 'description' => $role['description'], 'data_scope' => $role['data_scope']], $changes),
                $changes);
        }
        if ($added || $removed) {
            Audit::log('permission.changed', 'access', (int) $role['id'], ['removed' => $removed], ['added' => $added, 'role' => $role['slug']]);
        }

        Session::flash('success', "Role \"{$data['name']}\" saved: " . count($added) . ' added, ' . count($removed) . ' removed.');
        Response::redirect("/access/roles/{$role['id']}");
    }

    public static function destroy(array $p): void
    {
        $role = self::find((int) $p['id']);
        if ($role === null) {
            return;
        }
        if ((int) $role['is_system'] === 1) {
            Session::flash('error', 'Built-in roles cannot be deleted.');
            Response::redirect('/access/roles');
            return;
        }
        $users = (int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ?', [$role['id']]);
        if ($users > 0) {
            Session::flash('error', "Role \"{$role['name']}\" is assigned to {$users} user(s) (including deleted users). Move them to another role first.");
            Response::redirect('/access/roles');
            return;
        }
        if (!Gate::canGrantAll(Gate::rolePermissions((int) $role['id']))) {
            Response::error(403, 'You cannot delete a role with more access than you have.');
            return;
        }

        $perms = Gate::rolePermissions((int) $role['id']);
        Database::query('DELETE FROM roles WHERE id = ?', [$role['id']]);
        Audit::log('role.deleted', 'access', (int) $role['id'], ['name' => $role['name'], 'slug' => $role['slug'], 'permissions' => $perms]);
        Session::flash('success', "Role \"{$role['name']}\" deleted.");
        Response::redirect('/access/roles');
    }

    // -------------------------------------------------------------------------

    private static function find(int $id): ?array
    {
        $role = Database::fetch('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($role === null) {
            Response::error(404, 'Role not found.');
        }
        return $role;
    }

    private static function form(?array $role): void
    {
        $oldPerms = Session::pull('_old_perms');
        $old = Session::pull('_old', []);
        $isSuper = $role !== null && $role['slug'] === Gate::SUPER_ROLE;

        $checked = $oldPerms !== null
            ? array_fill_keys($oldPerms, true)
            : array_fill_keys($role ? Gate::rolePermissions((int) $role['id']) : [], true);

        Response::view('access/roles/form', [
            'title'      => ($role ? $role['name'] : 'New role') . ' · Roles',
            'flash'      => Session::takeFlash(),
            'errors'     => Session::pull('_errors', []),
            'role'       => $role,
            'values'     => $old ?: ($role ?? ['name' => '', 'description' => '', 'data_scope' => 'own']),
            'isSuper'    => $isSuper,
            'catalogue'  => AccessRules::permissionCatalogue(),
            'columns'    => AccessRules::actionColumns(),
            'checked'    => $checked,
            'actorHolds' => array_fill_keys(Gate::permissionsFor(Auth::user()), true),
            'userCount'  => $role ? (int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL', [$role['id']]) : 0,
        ]);
    }

    /**
     * @return array{0: array<string, string>, 1: array<int, string>, 2: array<string, string>}
     */
    private static function validated(?array $existing): array
    {
        $data = [
            'name'        => Request::input('name', 60),
            'description' => Request::input('description', 255),
            'data_scope'  => Request::input('data_scope', 10),
        ];
        // Built-in roles keep their name (code refers to their slug).
        if ($existing !== null && (int) $existing['is_system'] === 1) {
            $data['name'] = $existing['name'];
        }

        $perms = AccessRules::sanitisePermissionIds($_POST['permissions'] ?? []);

        $v = (new Validator())
            ->required('name', $data['name'], 'Role name')->maxLength('name', $data['name'], 60, 'Role name')
            ->maxLength('description', $data['description'], 255, 'Description')
            ->in('data_scope', $data['data_scope'], self::SCOPES, 'data scope');

        $excludeId = $existing['id'] ?? 0;
        if ($data['name'] !== '' && Database::value('SELECT 1 FROM roles WHERE name = ? AND id <> ?', [$data['name'], $excludeId])) {
            $v->add('name', 'A role with this name already exists.');
        }

        if ($existing === null) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($data['name'])), '_');
            $data['slug'] = $slug !== '' ? mb_substr($slug, 0, 60) : 'role';
            if ($data['slug'] === Gate::SUPER_ROLE || Database::value('SELECT 1 FROM roles WHERE slug = ?', [$data['slug']])) {
                $v->add('name', 'Choose a different role name.');
            }
            if (!Gate::canGrantAll(array_values($perms))) {
                $v->add('permissions', 'You can only include permissions that you hold yourself.');
            }
        }

        return [$data, $perms, $v->errors()];
    }

    /** @param list<int> $permissionIds */
    private static function savePermissions(int $roleId, array $permissionIds): void
    {
        Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        $ins = Database::connection()->prepare('INSERT INTO role_permissions (role_id, permission_id, granted_by) VALUES (?, ?, ?)');
        foreach ($permissionIds as $pid) {
            $ins->execute([$roleId, $pid, Auth::id()]);
        }
    }

    /**
     * @param array<string, string> $errors
     * @param array<int, string> $perms
     */
    private static function back(string $path, array $errors, array $perms): void
    {
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = array_intersect_key($_POST, array_flip(['name', 'description', 'data_scope']));
        $_SESSION['_old_perms'] = array_values($perms);
        Session::flash('error', 'Please correct the highlighted fields.');
        Response::redirect($path);
    }
}
