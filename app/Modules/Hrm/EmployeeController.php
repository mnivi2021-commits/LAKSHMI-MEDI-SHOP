<?php

declare(strict_types=1);

namespace App\Modules\Hrm;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\DataScope;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NumberSequence;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use DateTimeImmutable;

/**
 * HRM (first phase): Employees, Departments, Designations.
 * Attendance and leave are deliberately out of scope for now (see the spec).
 *
 * Employees follow the data scope: a Branch Manager (branch scope) with hrm.view
 * sees only the employees of the branches assigned to them.
 */
final class EmployeeController
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'resigned' => 'Resigned'];
    private const PER_PAGE = 25;

    public static function index(): void
    {
        $user = Auth::user();
        $f = [
            'q'          => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'branch'     => (int) ($_GET['branch'] ?? 0) ?: null,
            'department' => (int) ($_GET['department'] ?? 0) ?: null,
            'status'     => array_key_exists($_GET['status'] ?? '', self::STATUSES) ? $_GET['status'] : '',
            'reps'       => ($_GET['reps'] ?? '') === '1' ? '1' : '',
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        [$scopeSql, $params] = DataScope::for($user)->where('e.branch_id', 'e.id');
        $where = ['e.deleted_at IS NULL', $scopeSql];
        if ($f['q'] !== '') {
            $where[] = '(e.name LIKE ? OR e.employee_code LIKE ? OR e.short_name LIKE ? OR e.mobile LIKE ? OR e.email LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        foreach (['branch' => 'e.branch_id', 'department' => 'e.department_id', 'status' => 'e.status'] as $k => $col) {
            if ($f[$k] !== '' && $f[$k] !== null) {
                $where[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        if ($f['reps'] === '1') {
            $where[] = 'e.is_sales_rep = 1';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) FROM employees e WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $employees = Database::fetchAll(
            "SELECT e.*, b.branch_code, d.name AS department, g.name AS designation, m.name AS manager,
                    u.username, u.status AS user_status
             FROM employees e
             JOIN branches b ON b.id = e.branch_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN designations g ON g.id = e.designation_id
             LEFT JOIN employees m ON m.id = e.reporting_manager_id
             LEFT JOIN users u ON u.employee_id = e.id AND u.deleted_at IS NULL
             WHERE {$whereSql}
             ORDER BY FIELD(e.status, 'active', 'inactive', 'resigned'), e.name
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        [$bSql, $bParams] = DataScope::for($user)->branchListWhere('id');
        Response::view('hrm/index', [
            'title'       => 'Employees · HRM',
            'flash'       => Session::takeFlash(),
            'employees'   => $employees,
            'filters'     => $f,
            'branches'    => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND {$bSql} ORDER BY name", $bParams),
            'departments' => Database::fetchAll("SELECT id, name FROM departments ORDER BY name"),
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'canAdd'      => Gate::allows('hrm.add'),
            'canEdit'     => Gate::allows('hrm.edit'),
            'canDelete'   => Gate::allows('hrm.delete'),
            'canExport'   => Gate::allows('hrm.export'),
        ]);
    }

    public static function create(): void
    {
        self::form(null);
    }

    public static function edit(array $p): void
    {
        $emp = self::findInScope((int) $p['id']);
        if ($emp !== null) {
            self::form($emp);
        }
    }

    public static function store(): void
    {
        [$data, $errors] = self::validated(null);
        if ($errors) {
            self::back('/hrm/new', $errors);
            return;
        }
        $id = Database::transaction(static function () use ($data): int {
            $code = NumberSequence::next('employee');
            Database::query(
                'INSERT INTO employees (employee_code, name, short_name, mobile, email, date_of_birth, branch_id, department_id, designation_id,
                                        reporting_manager_id, joining_date, relieving_date, is_sales_rep, sales_role, area, coordinator_id,
                                        status, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $data['name'], $data['short_name'], $data['mobile'], $data['email'], $data['date_of_birth'], $data['branch_id'], $data['department_id'],
                 $data['designation_id'], $data['reporting_manager_id'], $data['joining_date'], $data['relieving_date'],
                 $data['is_sales_rep'], $data['sales_role'], $data['area'], $data['coordinator_id'], $data['status'], Auth::id(), Auth::id()]
            );
            return (int) Database::connection()->lastInsertId();
        });
        Audit::log('employee.created', 'hrm', $id, null, $data);
        Session::flash('success', "Employee {$data['name']} added.");
        Response::redirect('/hrm');
    }

    public static function update(array $p): void
    {
        $emp = self::findInScope((int) $p['id']);
        if ($emp === null) {
            return;
        }
        [$data, $errors] = self::validated($emp);
        if ($errors) {
            self::back("/hrm/{$emp['id']}/edit", $errors);
            return;
        }

        $disabledLogin = false;
        Database::transaction(static function () use ($data, $emp, &$disabledLogin): void {
            Database::query(
                'UPDATE employees SET name = ?, short_name = ?, mobile = ?, email = ?, date_of_birth = ?, branch_id = ?, department_id = ?, designation_id = ?,
                                      reporting_manager_id = ?, joining_date = ?, relieving_date = ?, is_sales_rep = ?, sales_role = ?, area = ?,
                                      coordinator_id = ?, status = ?, updated_by = ?
                 WHERE id = ?',
                [$data['name'], $data['short_name'], $data['mobile'], $data['email'], $data['date_of_birth'], $data['branch_id'], $data['department_id'],
                 $data['designation_id'], $data['reporting_manager_id'], $data['joining_date'], $data['relieving_date'],
                 $data['is_sales_rep'], $data['sales_role'], $data['area'], $data['coordinator_id'], $data['status'], Auth::id(), $emp['id']]
            );
            // A resigned employee must not keep a working CRM login (or mobile token).
            if ($data['status'] === 'resigned' && $emp['status'] !== 'resigned') {
                $userId = Database::value("SELECT id FROM users WHERE employee_id = ? AND deleted_at IS NULL AND status = 'active'", [$emp['id']]);
                if ($userId) {
                    Database::query("UPDATE users SET status = 'disabled', updated_by = ? WHERE id = ?", [Auth::id(), $userId]);
                    Database::query('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
                    $disabledLogin = true;
                }
            }
        });

        $old = array_intersect_key($emp, $data);
        $changes = array_diff_assoc(array_map(static fn ($v) => (string) ($v ?? ''), $data), array_map(static fn ($v) => (string) ($v ?? ''), $old));
        if ($changes) {
            Audit::log('employee.updated', 'hrm', (int) $emp['id'], array_intersect_key($old, $changes), $changes);
        }
        if ($disabledLogin) {
            Audit::log('user.disabled', 'users', null, null, ['reason' => 'employee resigned', 'employee_id' => (int) $emp['id']]);
        }
        Session::flash('success', "Employee {$data['name']} updated." . ($disabledLogin ? ' Their CRM login has been disabled.' : ''));
        Response::redirect('/hrm');
    }

    public static function destroy(array $p): void
    {
        $emp = self::findInScope((int) $p['id']);
        if ($emp === null) {
            return;
        }
        $inUse = (bool) Database::value(
            'SELECT EXISTS(SELECT 1 FROM sales_invoices WHERE employee_id = ?)
                 OR EXISTS(SELECT 1 FROM collections WHERE employee_id = ?)
                 OR EXISTS(SELECT 1 FROM sales_targets WHERE employee_id = ?)
                 OR EXISTS(SELECT 1 FROM customers WHERE employee_id = ? AND deleted_at IS NULL)
                 OR EXISTS(SELECT 1 FROM employees WHERE reporting_manager_id = ? AND deleted_at IS NULL)
                 OR EXISTS(SELECT 1 FROM users WHERE employee_id = ? AND deleted_at IS NULL)',
            array_fill(0, 6, $emp['id'])
        );
        if ($inUse) {
            Session::flash('error', "{$emp['name']} has sales, targets, customers, a login or team members linked and cannot be deleted. Mark them Resigned instead.");
            Response::redirect('/hrm');
            return;
        }
        Database::query('UPDATE employees SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [Auth::id(), $emp['id']]);
        Audit::log('employee.deleted', 'hrm', (int) $emp['id'], ['employee_code' => $emp['employee_code'], 'name' => $emp['name']]);
        Session::flash('success', "Employee {$emp['name']} deleted.");
        Response::redirect('/hrm');
    }

    public static function export(): void
    {
        [$scopeSql, $params] = DataScope::for(Auth::user())->where('e.branch_id', 'e.id');
        $rows = Database::fetchAll(
            "SELECT e.employee_code, e.name, e.short_name, e.mobile, e.email, b.branch_code, d.name AS department, g.name AS designation,
                    m.name AS manager, e.joining_date, e.relieving_date, IF(e.is_sales_rep, 'Yes', 'No') AS sales_rep, e.status,
                    e.date_of_birth, TIMESTAMPDIFF(YEAR, e.date_of_birth, CURDATE()) AS age, e.sales_role, e.area, co.name AS coordinator
             FROM employees e JOIN branches b ON b.id = e.branch_id
             LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations g ON g.id = e.designation_id
             LEFT JOIN employees m ON m.id = e.reporting_manager_id LEFT JOIN employees co ON co.id = e.coordinator_id
             WHERE e.deleted_at IS NULL AND {$scopeSql} ORDER BY e.name",
            $params
        );
        Audit::log('report.exported', 'hrm', null, null, ['report' => 'employees', 'rows' => count($rows)]);
        Csv::download('employees_' . date('Y-m-d') . '.csv',
            ['Code', 'Name', 'Short name', 'Mobile', 'Email', 'Branch', 'Department', 'Designation', 'Reporting manager', 'Joining', 'Relieving', 'Sales rep', 'Status',
             'Date of birth', 'Age', 'Sales role', 'Area', 'Sales coordinator'],
            array_map(static function (array $r): array {
                $r['sales_role'] = SalesTeamController::ROLES[$r['sales_role']] ?? '';
                return array_values($r);
            }, $rows));
    }

    // -------------------------------------------------------------------------

    private static function findInScope(int $id): ?array
    {
        [$scopeSql, $params] = DataScope::for(Auth::user())->where('e.branch_id', 'e.id');
        $emp = Database::fetch("SELECT e.* FROM employees e WHERE e.id = ? AND e.deleted_at IS NULL AND {$scopeSql}", array_merge([$id], $params));
        if ($emp === null) {
            Response::error(404, 'Employee not found.');
        }
        return $emp;
    }

    private static function form(?array $emp): void
    {
        $user = Auth::user();
        [$bSql, $bParams] = DataScope::for($user)->branchListWhere('id');
        $old = Session::pull('_old', []);
        Response::view('hrm/form', [
            'title'        => $emp ? 'Edit employee' : 'Add employee',
            'flash'        => Session::takeFlash(),
            'errors'       => Session::pull('_errors', []),
            'emp'          => $emp,
            'values'       => $old ?: ($emp ?? ['status' => 'active', 'joining_date' => date('Y-m-d')]),
            'branches'     => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bSql} ORDER BY name", $bParams),
            'departments'  => Database::fetchAll("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name"),
            'designations' => Database::fetchAll("SELECT id, name FROM designations WHERE status = 'active' ORDER BY name"),
            'roles'        => SalesTeamController::ROLES,
            'coordinators' => Database::fetchAll(
                "SELECT e.id, e.name, b.branch_code FROM employees e JOIN branches b ON b.id = e.branch_id
                 WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.sales_role = 'sales_coordinator' AND e.id <> ? ORDER BY e.name",
                [$emp['id'] ?? 0]
            ),
            'areas'        => array_column(Database::fetchAll("SELECT name AS area FROM sales_areas WHERE status = 'active'
                                                               UNION SELECT DISTINCT area FROM employees WHERE area IS NOT NULL AND deleted_at IS NULL AND area NOT LIKE '%,%' ORDER BY area"), 'area'),
            'managers'     => Database::fetchAll(
                "SELECT e.id, e.name, e.employee_code, b.branch_code FROM employees e JOIN branches b ON b.id = e.branch_id
                 WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.id <> ? ORDER BY e.name",
                [$emp['id'] ?? 0]
            ),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function validated(?array $existing): array
    {
        $data = [
            'name'                 => Request::input('name', 120),
            'short_name'           => mb_strtoupper(Request::input('short_name', 40)) ?: null,
            'mobile'               => Request::input('mobile', 20) ?: null,
            'email'                => mb_strtolower(Request::input('email', 150)) ?: null,
            'date_of_birth'        => Request::input('date_of_birth', 10) ?: null,
            'branch_id'            => (int) Request::input('branch_id', 10) ?: null,
            'department_id'        => (int) Request::input('department_id', 10) ?: null,
            'designation_id'       => (int) Request::input('designation_id', 10) ?: null,
            'reporting_manager_id' => (int) Request::input('reporting_manager_id', 10) ?: null,
            'joining_date'         => Request::input('joining_date', 10) ?: null,
            'relieving_date'       => Request::input('relieving_date', 10) ?: null,
            'is_sales_rep'         => Request::input('is_sales_rep', 1) === '1' ? 1 : 0,
            'sales_role'           => Request::input('sales_role', 20) ?: null,
            'area'                 => Request::input('area', 80) ?: null,
            'coordinator_id'       => (int) Request::input('coordinator_id', 10) ?: null,
            'status'               => Request::input('status', 10) ?: 'active',
        ];

        // A Sales Executive is always a sales representative; give a short name if none was typed.
        if ($data['sales_role'] === 'sales_executive') {
            $data['is_sales_rep'] = 1;
        }
        if ($data['is_sales_rep'] === 1 && $data['short_name'] === null && $data['name'] !== '') {
            $data['short_name'] = mb_strtoupper(mb_substr(preg_split('/\s+/', $data['name'])[0], 0, 40));
        }
        // Managers (team data scope) add people into their own team, so they keep seeing them.
        $me = Auth::user();
        $myScope = DataScope::for($me);
        if (!$myScope->isUnrestricted() && !empty($me['employee_id'])) {
            if ($data['reporting_manager_id'] === null && ($existing === null || (int) $existing['id'] !== (int) $me['employee_id'])) {
                $data['reporting_manager_id'] = (int) $me['employee_id'];
            }
        }

        $v = (new Validator())->required('name', $data['name'], 'Name')->maxLength('name', $data['name'], 120, 'Name')
            ->in('status', $data['status'], array_keys(self::STATUSES), 'status');
        if ($data['mobile']) {
            $v->mobile('mobile', $data['mobile']);
        }
        if ($data['email']) {
            $v->email('email', $data['email']);
        }
        if ($data['is_sales_rep'] === 1 && $data['short_name'] === null) {
            $v->add('short_name', 'Sales representatives need a short name for the dashboard (e.g. JANA).');
        }
        if ($data['sales_role'] !== null && !isset(SalesTeamController::ROLES[$data['sales_role']])) {
            $v->add('sales_role', 'Choose a valid sales role.');
        }
        if ($data['sales_role'] === 'sales_executive' && $data['is_sales_rep'] !== 1) {
            $v->add('sales_role', 'A Sales Executive must also be marked as a sales representative.');
        }
        if ($data['coordinator_id'] !== null) {
            if ($data['coordinator_id'] === (int) ($existing['id'] ?? 0)) {
                $v->add('coordinator_id', 'An employee cannot be their own coordinator.');
            } elseif (!Database::value("SELECT 1 FROM employees WHERE id = ? AND deleted_at IS NULL AND sales_role = 'sales_coordinator'", [$data['coordinator_id']])) {
                $v->add('coordinator_id', 'Choose a sales coordinator.');
            }
        }
        if ($data['date_of_birth'] !== null) {
            $dob = DateTimeImmutable::createFromFormat('!Y-m-d', $data['date_of_birth']);
            if ($dob === false || $dob->format('Y-m-d') !== $data['date_of_birth']) {
                $v->add('date_of_birth', 'Date of birth is not a valid date.');
            } else {
                $years = $dob->diff(new DateTimeImmutable('today'))->y;
                if ($dob > new DateTimeImmutable('today') || $years < 16 || $years > 80) {
                    $v->add('date_of_birth', 'Check the date of birth (age must be 16 to 80).');
                }
            }
        }
        foreach (['joining_date' => 'Joining date', 'relieving_date' => 'Relieving date'] as $field => $label) {
            if ($data[$field] !== null) {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data[$field]);
                if ($d === false || $d->format('Y-m-d') !== $data[$field]) {
                    $v->add($field, "{$label} is not a valid date.");
                }
            }
        }
        if ($data['joining_date'] && $data['relieving_date'] && $data['relieving_date'] < $data['joining_date']) {
            $v->add('relieving_date', 'Relieving date cannot be before the joining date.');
        }
        if ($data['status'] === 'resigned' && $data['relieving_date'] === null) {
            $v->add('relieving_date', 'Enter the relieving date for a resigned employee.');
        }

        $scope = DataScope::for(Auth::user());
        if ($data['branch_id'] === null || !Database::value('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL', [$data['branch_id']])) {
            $v->add('branch_id', 'Choose a branch.');
        } elseif (!$scope->allowsBranch($data['branch_id'])) {
            $v->add('branch_id', 'You do not have access to this branch.');
        }
        if ($data['department_id'] !== null && !Database::value('SELECT 1 FROM departments WHERE id = ?', [$data['department_id']])) {
            $v->add('department_id', 'Choose a valid department.');
        }
        if ($data['designation_id'] !== null && !Database::value('SELECT 1 FROM designations WHERE id = ?', [$data['designation_id']])) {
            $v->add('designation_id', 'Choose a valid designation.');
        }

        if ($data['reporting_manager_id'] !== null) {
            $id = $existing['id'] ?? null;
            if ($id !== null && $data['reporting_manager_id'] === (int) $id) {
                $v->add('reporting_manager_id', 'An employee cannot report to themselves.');
            } elseif (!Database::value('SELECT 1 FROM employees WHERE id = ? AND deleted_at IS NULL', [$data['reporting_manager_id']])) {
                $v->add('reporting_manager_id', 'Choose a valid reporting manager.');
            } elseif ($id !== null && self::isInTeamOf((int) $id, $data['reporting_manager_id'])) {
                $v->add('reporting_manager_id', 'This would create a loop: the chosen manager already reports (directly or indirectly) to this employee.');
            }
        }

        if ($data['short_name'] !== null) {
            $dup = Database::value('SELECT 1 FROM employees WHERE short_name = ? AND id <> ? AND deleted_at IS NULL AND status = \'active\'', [$data['short_name'], $existing['id'] ?? 0]);
            if ($dup) {
                $v->add('short_name', 'Another active employee already uses this short name.');
            }
        }
        return [$data, $v->errors()];
    }

    /** Is $candidateId somewhere below $employeeId in the reporting tree? */
    private static function isInTeamOf(int $employeeId, int $candidateId): bool
    {
        return (bool) Database::value(
            'WITH RECURSIVE team AS (
                 SELECT id, 0 AS depth FROM employees WHERE reporting_manager_id = ?
                 UNION ALL
                 SELECT e.id, t.depth + 1 FROM employees e JOIN team t ON e.reporting_manager_id = t.id WHERE t.depth < 20
             )
             SELECT 1 FROM team WHERE id = ? LIMIT 1',
            [$employeeId, $candidateId]
        );
    }

    /** @param array<string, string> $errors */
    private static function back(string $path, array $errors): void
    {
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = $_POST;
        Session::flash('error', 'Please correct the highlighted fields.');
        Response::redirect($path);
    }
}
