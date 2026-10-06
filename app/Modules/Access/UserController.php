<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\PasswordPolicy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/** User management: list, add, edit, enable/disable, reset password, unlock, delete, per-user overrides. */
final class UserController
{
    private const PER_PAGE = 20;

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public static function index(): void
    {
        $q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $roleId = (int) ($_GET['role'] ?? 0);
        $status = in_array($_GET['status'] ?? '', ['active', 'disabled'], true) ? $_GET['status'] : '';
        $page   = max(1, (int) ($_GET['page'] ?? 1));

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($roleId > 0) {
            $where[] = 'u.role_id = ?';
            $params[] = $roleId;
        }
        if ($status !== '') {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $users = Database::fetchAll(
            "SELECT u.id, u.name, u.username, u.email, u.mobile, u.status, u.must_change_password, u.last_login_at,
                    u.locked_until > NOW() AS is_locked, u.role_id,
                    r.name AS role_name, r.slug AS role_slug, r.data_scope,
                    e.name AS employee_name, e.employee_code,
                    (SELECT GROUP_CONCAT(b.branch_code ORDER BY b.branch_code SEPARATOR ', ')
                       FROM user_branches ub JOIN branches b ON b.id = ub.branch_id WHERE ub.user_id = u.id) AS branch_codes
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN employees e ON e.id = u.employee_id
             WHERE {$whereSql}
             ORDER BY u.status = 'active' DESC, u.name
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );
        foreach ($users as &$u) {
            $u['manageable'] = AccessRules::canManageUser($u);
        }
        unset($u);

        Response::view('access/users/index', [
            'title'   => 'Users · Access',
            'flash'   => Session::takeFlash(),
            'users'   => $users,
            'roles'   => Database::fetchAll('SELECT id, name FROM roles ORDER BY id'),
            'filters' => ['q' => $q, 'role' => $roleId, 'status' => $status],
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
        ]);
    }

    // -------------------------------------------------------------------------
    // Create / edit
    // -------------------------------------------------------------------------

    public static function create(): void
    {
        self::form(null);
    }

    public static function edit(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user !== null) {
            self::form($user);
        }
    }

    public static function store(): void
    {
        [$data, $errors] = self::validated(null);
        if ($errors) {
            self::back('/access/users/new', $errors);
            return;
        }

        $temp = PasswordPolicy::generateTemporary();
        $id = Database::transaction(static function () use ($data, $temp): int {
            Database::query(
                "INSERT INTO users (role_id, employee_id, name, username, email, mobile, password_hash, status, must_change_password, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?)",
                [$data['role_id'], $data['employee_id'], $data['name'], $data['username'], $data['email'], $data['mobile'],
                 password_hash($temp, PASSWORD_DEFAULT), Auth::id(), Auth::id()]
            );
            $id = (int) Database::connection()->lastInsertId();
            self::saveBranches($id, $data['branch_ids']);
            return $id;
        });

        Audit::log('user.created', 'users', $id, null, self::auditView($data));
        Session::flash('success', "User {$data['username']} created.");
        Session::flash('credential', "Temporary password for {$data['username']}: {$temp}  (shown once - share it privately; they must change it at first sign-in)");
        Response::redirect('/access/users');
    }

    public static function update(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }

        [$data, $errors] = self::validated($user);
        if ($errors) {
            self::back("/access/users/{$user['id']}/edit", $errors);
            return;
        }

        $old = self::auditView([
            'name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'mobile' => $user['mobile'],
            'role_id' => (int) $user['role_id'], 'employee_id' => $user['employee_id'] !== null ? (int) $user['employee_id'] : null,
            'branch_ids' => self::branchIds((int) $user['id']),
        ]);

        Database::transaction(static function () use ($user, $data): void {
            Database::query(
                'UPDATE users SET role_id = ?, employee_id = ?, name = ?, username = ?, email = ?, mobile = ?, updated_by = ? WHERE id = ?',
                [$data['role_id'], $data['employee_id'], $data['name'], $data['username'], $data['email'], $data['mobile'], Auth::id(), $user['id']]
            );
            self::saveBranches((int) $user['id'], $data['branch_ids']);
        });

        Gate::forget((int) $user['id']);
        Audit::log('user.updated', 'users', (int) $user['id'], $old, self::auditView($data));
        Session::flash('success', "User {$data['username']} updated.");
        Response::redirect('/access/users');
    }

    // -------------------------------------------------------------------------
    // Account actions
    // -------------------------------------------------------------------------

    public static function toggleStatus(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }
        $disable = $user['status'] === 'active';

        if ($disable && AccessRules::isSelf((int) $user['id'])) {
            self::fail('You cannot disable your own account.');
            return;
        }
        if ($disable && AccessRules::isLastActiveSuper((int) $user['id'])) {
            self::fail('This is the only active Admin Head. Add another Admin Head before disabling this account.');
            return;
        }

        Database::transaction(static function () use ($user, $disable): void {
            Database::query('UPDATE users SET status = ?, updated_by = ? WHERE id = ?', [$disable ? 'disabled' : 'active', Auth::id(), $user['id']]);
            if ($disable) {
                Database::query('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
            }
        });

        Audit::log($disable ? 'user.disabled' : 'user.enabled', 'users', (int) $user['id'], ['status' => $user['status']], ['status' => $disable ? 'disabled' : 'active']);
        Session::flash('success', "{$user['username']} " . ($disable ? 'disabled and signed out everywhere.' : 'enabled.'));
        Response::redirect('/access/users');
    }

    public static function resetPassword(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }
        if (AccessRules::isSelf((int) $user['id'])) {
            self::fail('Use "Change password" to change your own password.');
            return;
        }

        $temp = PasswordPolicy::generateTemporary();
        Database::transaction(static function () use ($user, $temp): void {
            Database::query(
                'UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NOW(),
                                  failed_login_count = 0, locked_until = NULL, updated_by = ? WHERE id = ?',
                [password_hash($temp, PASSWORD_DEFAULT), Auth::id(), $user['id']]
            );
            Database::query('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
        });

        Audit::log('user.password_reset', 'users', (int) $user['id']);
        Session::flash('credential', "New temporary password for {$user['username']}: {$temp}  (shown once; they are signed out everywhere and must change it at next sign-in)");
        Response::redirect('/access/users');
    }

    public static function unlock(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }
        Database::query('UPDATE users SET failed_login_count = 0, locked_until = NULL, updated_by = ? WHERE id = ?', [Auth::id(), $user['id']]);
        // Clear recent failed attempts so the throttle releases the username / email too.
        Database::query(
            'DELETE FROM login_attempts WHERE success = 0 AND identifier IN (?, ?) AND attempted_at > NOW() - INTERVAL 1 DAY',
            [mb_strtolower($user['username']), mb_strtolower($user['email'])]
        );
        Audit::log('user.unlocked', 'users', (int) $user['id']);
        Session::flash('success', "{$user['username']} unlocked.");
        Response::redirect('/access/users');
    }

    public static function destroy(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }
        if (AccessRules::isSelf((int) $user['id'])) {
            self::fail('You cannot delete your own account.');
            return;
        }
        if (AccessRules::isLastActiveSuper((int) $user['id'])) {
            self::fail('This is the only active Admin Head and cannot be deleted.');
            return;
        }

        Database::transaction(static function () use ($user): void {
            Database::query("UPDATE users SET deleted_at = NOW(), status = 'disabled', updated_by = ? WHERE id = ?", [Auth::id(), $user['id']]);
            Database::query('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
            Database::query('DELETE FROM user_branches WHERE user_id = ?', [$user['id']]);
        });

        Audit::log('user.deleted', 'users', (int) $user['id'], ['username' => $user['username'], 'name' => $user['name']]);
        Session::flash('success', "{$user['username']} deleted.");
        Response::redirect('/access/users');
    }

    // -------------------------------------------------------------------------
    // Per-user permission overrides (access.manage)
    // -------------------------------------------------------------------------

    public static function permissions(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }
        if ($user['role_slug'] === Gate::SUPER_ROLE) {
            self::fail('Admin Head always has every permission; overrides do not apply.');
            return;
        }

        $overrides = Database::query('SELECT permission_id, effect FROM user_permissions WHERE user_id = ?', [$user['id']])->fetchAll(\PDO::FETCH_KEY_PAIR);
        Response::view('access/users/permissions', [
            'title'      => 'Permissions · ' . $user['name'],
            'flash'      => Session::takeFlash(),
            'user'       => $user,
            'catalogue'  => AccessRules::permissionCatalogue(),
            'columns'    => AccessRules::actionColumns(),
            'roleSlugs'  => array_fill_keys(Gate::rolePermissions((int) $user['role_id']), true),
            'overrides'  => $overrides,
            'actorHolds' => array_fill_keys(Gate::permissionsFor(Auth::user()), true),
            'isSelf'     => AccessRules::isSelf((int) $user['id']),
        ]);
    }

    public static function savePermissions(array $p): void
    {
        $user = self::findManageable((int) $p['id']);
        if ($user === null) {
            return;
        }
        if ($user['role_slug'] === Gate::SUPER_ROLE || AccessRules::isSelf((int) $user['id'])) {
            self::fail('Overrides cannot be changed for this account.');
            return;
        }

        $submitted = is_array($_POST['override'] ?? null) ? $_POST['override'] : [];
        $catalogue = Database::query('SELECT id, slug FROM permissions')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $new = [];
        foreach ($submitted as $permId => $effect) {
            $permId = (int) $permId;
            if (isset($catalogue[$permId]) && in_array($effect, ['grant', 'deny'], true)) {
                $new[$permId] = $effect;
            }
        }

        $old = Database::query('SELECT permission_id, effect FROM user_permissions WHERE user_id = ?', [$user['id']])->fetchAll(\PDO::FETCH_KEY_PAIR);
        $changedSlugs = [];
        foreach ($new + $old as $permId => $_) {
            if (($new[$permId] ?? null) !== ($old[$permId] ?? null)) {
                $changedSlugs[] = $catalogue[$permId];
            }
        }
        if (!Gate::canGrantAll($changedSlugs)) {
            self::fail('You can only grant or deny permissions that you hold yourself.');
            return;
        }

        Database::transaction(static function () use ($user, $new): void {
            Database::query('DELETE FROM user_permissions WHERE user_id = ?', [$user['id']]);
            $ins = Database::connection()->prepare('INSERT INTO user_permissions (user_id, permission_id, effect, granted_by) VALUES (?, ?, ?, ?)');
            foreach ($new as $permId => $effect) {
                $ins->execute([$user['id'], $permId, $effect, Auth::id()]);
            }
        });

        Gate::forget((int) $user['id']);
        $named = static fn (array $set): array => array_map(static fn ($id) => $catalogue[$id] . ':' . $set[$id], array_keys($set));
        Audit::log('user_permission.changed', 'access', (int) $user['id'], ['overrides' => $named($old)], ['overrides' => $named($new)]);
        Session::flash('success', 'Permission overrides saved for ' . $user['username'] . '.');
        Response::redirect("/access/users/{$user['id']}/permissions");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Loads a user the current actor may manage; responds 404/403 otherwise. */
    private static function findManageable(int $id): ?array
    {
        $user = Database::fetch(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.data_scope
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.deleted_at IS NULL',
            [$id]
        );
        if ($user === null) {
            Response::error(404, 'User not found.');
            return null;
        }
        if (!AccessRules::canManageUser($user)) {
            Response::error(403, 'You cannot manage a user who has more access than you.');
            return null;
        }
        return $user;
    }

    private static function form(?array $user): void
    {
        $old = Session::pull('_old', []);
        $values = $old ?: ($user === null ? ['branch_ids' => []] : [
            'name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'mobile' => $user['mobile'],
            'role_id' => (int) $user['role_id'], 'employee_id' => $user['employee_id'], 'branch_ids' => self::branchIds((int) $user['id']),
        ]);

        Response::view('access/users/form', [
            'title'     => ($user ? 'Edit user' : 'Add user') . ' · Access',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_errors', []),
            'user'      => $user,
            'values'    => $values,
            'roles'     => AccessRules::assignableRoles(),
            'roleLocked'=> $user !== null && AccessRules::isSelf((int) $user['id']),
            'employees' => Database::fetchAll(
                'SELECT e.id, e.name, e.employee_code, b.branch_code
                 FROM employees e JOIN branches b ON b.id = e.branch_id
                 LEFT JOIN users u ON u.employee_id = e.id AND u.deleted_at IS NULL AND u.id <> ?
                 WHERE e.deleted_at IS NULL AND e.status = \'active\' AND u.id IS NULL
                 ORDER BY e.name',
                [$user['id'] ?? 0]
            ),
            'branches'  => Database::fetchAll("SELECT id, branch_code, name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY name"),
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function validated(?array $existing): array
    {
        $data = [
            'name'        => Request::input('name', 100),
            'username'    => mb_strtolower(Request::input('username', 50)),
            'email'       => mb_strtolower(Request::input('email', 150)),
            'mobile'      => Request::input('mobile', 20),
            'role_id'     => (int) Request::input('role_id', 10),
            'employee_id' => (int) Request::input('employee_id', 10) ?: null,
            'branch_ids'  => array_values(array_unique(array_map('intval', is_array($_POST['branch_ids'] ?? null) ? $_POST['branch_ids'] : []))),
        ];

        // Users may not change their own role (no self-promotion / self-lockout).
        if ($existing !== null && AccessRules::isSelf((int) $existing['id'])) {
            $data['role_id'] = (int) $existing['role_id'];
        }

        $v = (new Validator())
            ->required('name', $data['name'], 'Name')->maxLength('name', $data['name'], 100, 'Name')
            ->required('username', $data['username'], 'Username')
            ->pattern('username', $data['username'], '/^[a-z0-9][a-z0-9._-]{2,49}$/', 'Username: 3-50 letters, digits, dot, dash or underscore.')
            ->required('email', $data['email'], 'Email')->email('email', $data['email'])->maxLength('email', $data['email'], 150, 'Email')
            ->mobile('mobile', $data['mobile']);

        $excludeId = $existing['id'] ?? 0;
        if ($data['username'] !== '' && Database::value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$data['username'], $excludeId])) {
            $v->add('username', 'This username is already taken.');
        }
        if ($data['email'] !== '' && Database::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$data['email'], $excludeId])) {
            $v->add('email', 'This email is already used by another user.');
        }

        $role = Database::fetch("SELECT id, slug, data_scope FROM roles WHERE id = ? AND status = 'active'", [$data['role_id']]);
        $roleUnchanged = $existing !== null && (int) $existing['role_id'] === $data['role_id'];
        if ($role === null) {
            $v->add('role_id', 'Choose a role.');
        } elseif (!$roleUnchanged && !AccessRules::canAssignRole((int) $role['id'], $role['slug'])) {
            $v->add('role_id', 'You cannot assign a role with more access than you have.');
        } elseif ($existing !== null && $existing['role_slug'] === Gate::SUPER_ROLE && $role['slug'] !== Gate::SUPER_ROLE
                  && AccessRules::isLastActiveSuper((int) $existing['id'])) {
            $v->add('role_id', 'This is the only active Admin Head. Add another Admin Head first.');
        }

        if ($data['employee_id'] !== null) {
            $taken = Database::value('SELECT id FROM users WHERE employee_id = ? AND deleted_at IS NULL AND id <> ?', [$data['employee_id'], $excludeId]);
            $exists = Database::value('SELECT 1 FROM employees WHERE id = ? AND deleted_at IS NULL', [$data['employee_id']]);
            if (!$exists) {
                $v->add('employee_id', 'Choose a valid employee.');
            } elseif ($taken) {
                $v->add('employee_id', 'This employee is already linked to another user.');
            }
        }

        if ($role !== null) {
            if (in_array($role['data_scope'], ['own', 'team'], true) && $data['employee_id'] === null) {
                $v->add('employee_id', 'This role sees only the employee\'s own/team records, so link an employee.');
            }
            if ($role['data_scope'] === 'branch' && $data['branch_ids'] === []) {
                $v->add('branch_ids', 'This role sees selected branches only. Tick at least one branch.');
            }
        }
        if ($data['branch_ids'] !== []) {
            $valid = (int) Database::value(
                'SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($data['branch_ids']), '?')) . ')',
                $data['branch_ids']
            );
            if ($valid !== count($data['branch_ids'])) {
                $v->add('branch_ids', 'Choose valid branches.');
            }
        }

        $data['mobile'] = $data['mobile'] !== '' ? preg_replace('/[\s-]/', '', $data['mobile']) : null;
        return [$data, $v->errors()];
    }

    /** @param list<int> $branchIds */
    private static function saveBranches(int $userId, array $branchIds): void
    {
        Database::query('DELETE FROM user_branches WHERE user_id = ?', [$userId]);
        $ins = Database::connection()->prepare('INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)');
        foreach ($branchIds as $b) {
            $ins->execute([$userId, $b]);
        }
    }

    /** @return list<int> */
    private static function branchIds(int $userId): array
    {
        return array_map('intval', Database::query('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id', [$userId])->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $d */
    private static function auditView(array $d): array
    {
        return array_intersect_key($d, array_flip(['name', 'username', 'email', 'mobile', 'role_id', 'employee_id', 'branch_ids']));
    }

    /** @param array<string, string> $errors */
    private static function back(string $path, array $errors): void
    {
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = array_intersect_key($_POST, array_flip(['name', 'username', 'email', 'mobile', 'role_id', 'employee_id', 'branch_ids']));
        Session::flash('error', 'Please correct the highlighted fields.');
        Response::redirect($path);
    }

    private static function fail(string $message): void
    {
        Session::flash('error', $message);
        Response::redirect('/access/users');
    }
}
