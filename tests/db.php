<?php

declare(strict_types=1);

/*
 * Database integration tests:  php tests/db.php
 * Needs the seeded demo database. Everything runs inside a transaction that is
 * rolled back at the end, so no data is changed.
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$passed = 0;
$failed = 0;
function check(string $name, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $userId = static fn (string $username): int => (int) Database::value('SELECT id FROM users WHERE username = ?', [$username]);
    $admin = Auth::loadUser($userId('admin'));
    $coord = Auth::loadUser($userId('coordinator'));
    $jana  = Auth::loadUser($userId('jana'));
    $total = (int) Database::value('SELECT COUNT(*) FROM permissions');

    // --- Gate: role permissions --------------------------------------------------
    check('admin head holds every permission', $total, count(Gate::permissionsFor($admin)));
    check('coordinator holds 29 permissions (incl. daily entry, requests)', 29, count(Gate::permissionsFor($coord)));
    check('coordinator can view dashboard', true, Gate::allows('dashboard.view', $coord));
    check('coordinator can import pending orders', true, Gate::allows('pending_orders.import', $coord));
    check('coordinator cannot manage users', false, Gate::allows('users.view', $coord));
    check('coordinator cannot change access', false, Gate::allows('access.manage', $coord));
    check('coordinator cannot delete sales', false, Gate::allows('sales.delete', $coord));
    check('coordinator cannot change settings', false, Gate::allows('settings.manage', $coord));
    check('sales exec cannot import', false, Gate::allows('sales.import', $jana));
    check('unknown permission denied', false, Gate::allows('nonexistent.thing', $admin));

    // --- Gate: per-user overrides --------------------------------------------------
    $pid = static fn (string $slug): int => (int) Database::value('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    Database::query("INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'grant')", [$jana['id'], $pid('reports.view')]);
    Database::query("INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'deny')", [$jana['id'], $pid('leads.add')]);
    Gate::forget();
    check('user grant adds permission', true, Gate::allows('reports.view', $jana));
    check('user deny removes role permission', false, Gate::allows('leads.add', $jana));
    check('other role permissions unaffected', true, Gate::allows('leads.edit', $jana));

    // --- Escalation guard --------------------------------------------------------
    check('coordinator cannot grant users.delete', false, Gate::canGrantAll(['users.delete'], $coord));
    check('coordinator can grant what it holds', true, Gate::canGrantAll(['dashboard.view', 'reports.view'], $coord));
    check('admin can grant anything', true, Gate::canGrantAll(Gate::permissionsFor($admin), $admin));

    // --- DataScope: all / own ------------------------------------------------------
    $scope = DataScope::for($admin);
    check('admin scope unrestricted', ['1 = 1', []], $scope->where('s.branch_id', 's.employee_id'));

    $scope = DataScope::for($jana);
    check('own scope filters by own employee', ['s.employee_id IN (?)', [2]], $scope->where('s.branch_id', 's.employee_id'));
    check('own scope hides tables without employee column', ['1 = 0', []], $scope->where('b.id', null));

    [$sql, $params] = $scope->where('v.branch_id', 'v.employee_id');
    $janaSales = Database::value("SELECT SUM(taxable_value) FROM v_sales_documents v WHERE {$sql}", $params);
    $direct = Database::value('SELECT SUM(taxable_value) FROM v_sales_documents WHERE employee_id = 2');
    check('own scope sales = JANA sales only', $direct, $janaSales);
    $others = Database::value("SELECT COUNT(*) FROM v_sales_documents v WHERE {$sql} AND v.employee_id <> 2", $params);
    check('own scope returns no other employee rows', 0, (int) $others);

    // --- DataScope: own without employee -> nothing --------------------------------
    $orphan = $jana;
    $orphan['employee_id'] = null;
    check('own scope without employee sees nothing', ['1 = 0', []], DataScope::for($orphan)->where('s.branch_id', 's.employee_id'));

    // --- DataScope: team (Rajesh manages JANA + MUKESH) ----------------------------
    $managerRole = (int) Database::value("SELECT id FROM roles WHERE slug = 'sales_manager'");
    Database::query(
        "INSERT INTO users (role_id, employee_id, name, username, email, password_hash) VALUES (?, 1, 'Rajesh Kumar', 'rajesh_t', 'rajesh_t@example.com', 'x')",
        [$managerRole]
    );
    $rajesh = Auth::loadUser((int) $pdo->lastInsertId());
    check('team scope = manager + reports', ['s.employee_id IN (?,?,?)', [1, 2, 3]], DataScope::for($rajesh)->where('s.branch_id', 's.employee_id'));

    // Two-level tree: put PRAKASH (4) under MUKESH (3) -> Rajesh now sees 4 as well.
    Database::query('UPDATE employees SET reporting_manager_id = 3 WHERE id = 4');
    check('team scope follows the whole tree', [1, 2, 3, 4], DataScope::for($rajesh)->employeeIds);

    // --- DataScope: branch ------------------------------------------------------------
    $bmRole = (int) Database::value("SELECT id FROM roles WHERE slug = 'branch_manager'");
    Database::query(
        "INSERT INTO users (role_id, employee_id, name, username, email, password_hash) VALUES (?, 8, 'Senthil Nathan', 'senthil_t', 'senthil_t@example.com', 'x')",
        [$bmRole]
    );
    $senthilId = (int) $pdo->lastInsertId();
    $senthil = Auth::loadUser($senthilId);
    check('branch scope with no branches sees nothing', ['1 = 0', []], DataScope::for($senthil)->where('s.branch_id', 's.employee_id'));
    Database::query('INSERT INTO user_branches (user_id, branch_id) VALUES (?, 2)', [$senthilId]);
    $scope = DataScope::for($senthil);
    check('branch scope filters by assigned branch', ['s.branch_id IN (?)', [2]], $scope->where('s.branch_id', 's.employee_id'));
    check('branch scope allowsBranch(2)', true, $scope->allowsBranch(2));
    check('branch scope denies branch 1', false, $scope->allowsBranch(1));

    // --- Injection safety ---------------------------------------------------------------
    try {
        DataScope::for($jana)->where('s.branch_id', 's.employee_id; DROP TABLE users');
        check('invalid column name rejected', true, false);
    } catch (InvalidArgumentException) {
        check('invalid column name rejected', true, true);
    }
} finally {
    $pdo->rollBack();
}

echo PHP_EOL . "{$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
