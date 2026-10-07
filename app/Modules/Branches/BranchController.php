<?php

declare(strict_types=1);

namespace App\Modules\Branches;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\DataScope;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/**
 * Branch Details master data: Branch Code, Name, Address, City, State, Pincode,
 * Contact Number, Email, Branch Manager, Status. See docs/MODULES.md.
 *
 * Visibility follows the user's data scope: a 'branch' scope user (e.g. Branch
 * Manager) only sees the branches assigned to them in user_branches; 'own'/'team'
 * scope users have no branches.view permission in the seeded roles, so they never
 * reach this controller.
 */
final class BranchController
{
    private const PER_PAGE = 20;

    public static function index(): void
    {
        $user = Auth::user();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        // Columns are qualified with b. throughout: the listing joins employees (also
        // has id/deleted_at/name/status), which would otherwise make them ambiguous.
        [$scopeSql, $params] = DataScope::for($user)->where('b.id', null);
        $where = ['b.deleted_at IS NULL', $scopeSql];
        if ($q !== '') {
            $where[] = '(b.name LIKE ? OR b.branch_code LIKE ? OR b.city LIKE ? OR b.state LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) FROM branches b WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $branches = Database::fetchAll(
            "SELECT b.*, m.name AS manager_name,
                    (SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id AND e.deleted_at IS NULL) AS employee_count,
                    (SELECT COUNT(*) FROM customers c WHERE c.branch_id = b.id AND c.deleted_at IS NULL) AS customer_count
             FROM branches b LEFT JOIN employees m ON m.id = b.manager_employee_id
             WHERE {$whereSql}
             ORDER BY b.status = 'active' DESC, b.name
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        // Sales team of one branch: name, area, division, month target with 80%, opening outstanding with 60%
        [$tw, $tp] = DataScope::for($user)->branchListWhere('id');
        $teamBranches = Database::fetchAll("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$tw} ORDER BY name", $tp);
        $teamBranch = (int) ($_GET['team_branch'] ?? 0);
        $teamBranch = in_array($teamBranch, array_map('intval', array_column($teamBranches, 'id')), true) ? $teamBranch : 0;
        $teamMonth = date('Y-m-01');
        [$ew, $ep] = DataScope::for($user)->where('e.branch_id', 'e.id');
        $team = Database::fetchAll(
            "SELECT e.id, e.name, e.short_name, e.area, b.name AS branch,
                    (SELECT GROUP_CONCAT(DISTINCT d.name ORDER BY d.id SEPARATOR ', ') FROM annual_targets t JOIN divisions d ON d.id = t.division_id
                     JOIN financial_years fy ON fy.id = t.financial_year_id
                     WHERE t.employee_id = e.id AND ? BETWEEN fy.start_date AND fy.end_date) AS divisions,
                    (SELECT SUM(st.sales_target) FROM sales_targets st WHERE st.employee_id = e.id AND st.target_month = ?) AS target,
                    (SELECT o.opening_outstanding FROM rep_month_openings o WHERE o.employee_id = e.id AND o.opening_month = ?) AS opening
             FROM employees e JOIN branches b ON b.id = e.branch_id
             WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.is_sales_rep = 1 AND {$ew}" . ($teamBranch ? ' AND e.branch_id = ' . $teamBranch : '') . "
             ORDER BY b.name, e.area, e.name",
            array_merge([$teamMonth, $teamMonth, $teamMonth], $ep)
        );

        Response::view('branches/index', [
            'teamBranches' => $teamBranches,
            'teamBranch'   => $teamBranch,
            'team'         => $team,
            'teamMonth'    => $teamMonth,
            'pcts'         => \App\Modules\Dashboard\EntryController::pcts(),
            'title'    => 'Branch Details',
            'flash'    => Session::takeFlash(),
            'branches' => $branches,
            'filters'  => ['q' => $q, 'status' => $status],
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'canAdd'   => Gate::allows('branches.add'),
            'canEdit'  => Gate::allows('branches.edit'),
            'canDelete'=> Gate::allows('branches.delete'),
            'canExport'=> Gate::allows('branches.export'),
        ]);
    }

    public static function create(): void
    {
        self::form(null);
    }

    public static function edit(array $p): void
    {
        $branch = self::findInScope((int) $p['id']);
        if ($branch !== null) {
            self::form($branch);
        }
    }

    public static function store(): void
    {
        [$data, $errors] = self::validated(null);
        if ($errors) {
            self::back('/branches/new', $errors);
            return;
        }

        Database::query(
            "INSERT INTO branches (branch_code, name, address, city, state, pincode, contact_number, email, manager_employee_id, status, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)",
            [$data['branch_code'], $data['name'], $data['address'], $data['city'], $data['state'], $data['pincode'],
             $data['contact_number'], $data['email'], $data['manager_employee_id'], Auth::id(), Auth::id()]
        );
        $id = (int) Database::connection()->lastInsertId();

        Audit::log('branch.created', 'branches', $id, null, $data);
        Session::flash('success', "Branch {$data['name']} created.");
        Response::redirect('/branches');
    }

    public static function update(array $p): void
    {
        $branch = self::findInScope((int) $p['id']);
        if ($branch === null) {
            return;
        }

        [$data, $errors] = self::validated($branch);
        if ($errors) {
            self::back("/branches/{$branch['id']}/edit", $errors);
            return;
        }

        $old = array_intersect_key($branch, $data);
        Database::query(
            'UPDATE branches SET branch_code = ?, name = ?, address = ?, city = ?, state = ?, pincode = ?,
                                 contact_number = ?, email = ?, manager_employee_id = ?, updated_by = ? WHERE id = ?',
            [$data['branch_code'], $data['name'], $data['address'], $data['city'], $data['state'], $data['pincode'],
             $data['contact_number'], $data['email'], $data['manager_employee_id'], Auth::id(), $branch['id']]
        );

        $changes = array_diff_assoc($data, $old);
        if ($changes) {
            Audit::log('branch.updated', 'branches', (int) $branch['id'], array_intersect_key($old, $changes), $changes);
        }
        Session::flash('success', "Branch {$data['name']} updated.");
        Response::redirect('/branches');
    }

    public static function toggleStatus(array $p): void
    {
        $branch = self::findInScope((int) $p['id']);
        if ($branch === null) {
            return;
        }
        $disable = $branch['status'] === 'active';
        if ($disable && self::hasActiveDependents((int) $branch['id'])) {
            Session::flash('error', "Branch {$branch['name']} has active employees or customers and cannot be disabled. Reassign them first.");
            Response::redirect('/branches');
            return;
        }

        Database::query('UPDATE branches SET status = ?, updated_by = ? WHERE id = ?', [$disable ? 'inactive' : 'active', Auth::id(), $branch['id']]);
        Audit::log($disable ? 'branch.disabled' : 'branch.enabled', 'branches', (int) $branch['id'], ['status' => $branch['status']], ['status' => $disable ? 'inactive' : 'active']);
        Session::flash('success', "{$branch['name']} " . ($disable ? 'disabled.' : 'enabled.'));
        Response::redirect('/branches');
    }

    public static function destroy(array $p): void
    {
        $branch = self::findInScope((int) $p['id']);
        if ($branch === null) {
            return;
        }
        if (self::hasActiveDependents((int) $branch['id'])) {
            Session::flash('error', "Branch {$branch['name']} has employees, customers or transactions and cannot be deleted. Disable it instead.");
            Response::redirect('/branches');
            return;
        }

        Database::query('UPDATE branches SET deleted_at = NOW(), deleted_by = ?, status = ? WHERE id = ?', [Auth::id(), 'inactive', $branch['id']]);
        Audit::log('branch.deleted', 'branches', (int) $branch['id'], ['branch_code' => $branch['branch_code'], 'name' => $branch['name']]);
        Session::flash('success', "Branch {$branch['name']} deleted.");
        Response::redirect('/branches');
    }

    public static function export(): void
    {
        $user = Auth::user();
        [$scopeSql, $params] = DataScope::for($user)->where('b.id', null);
        $rows = Database::fetchAll(
            "SELECT b.branch_code, b.name, b.address, b.city, b.state, b.pincode, b.contact_number, b.email, b.status, m.name AS manager_name
             FROM branches b LEFT JOIN employees m ON m.id = b.manager_employee_id
             WHERE b.deleted_at IS NULL AND {$scopeSql} ORDER BY b.name",
            $params
        );
        Audit::log('report.exported', 'branches', null, null, ['report' => 'branch_details', 'rows' => count($rows)]);

        Csv::download('branches_' . date('Y-m-d') . '.csv',
            ['Branch Code', 'Name', 'Address', 'City', 'State', 'Pincode', 'Contact', 'Email', 'Status', 'Manager'],
            array_map(static fn (array $r): array => array_values($r), $rows));
    }

    // -------------------------------------------------------------------------

    private static function findInScope(int $id): ?array
    {
        [$scopeSql, $params] = DataScope::for(Auth::user())->branchListWhere('id');
        $branch = Database::fetch("SELECT * FROM branches WHERE id = ? AND deleted_at IS NULL AND {$scopeSql}", array_merge([$id], $params));
        if ($branch === null) {
            Response::error(404, 'Branch not found.');
        }
        return $branch;
    }

    private static function hasActiveDependents(int $branchId): bool
    {
        return (bool) Database::value(
            'SELECT EXISTS(SELECT 1 FROM employees WHERE branch_id = ? AND deleted_at IS NULL)
                 OR EXISTS(SELECT 1 FROM customers WHERE branch_id = ? AND deleted_at IS NULL)
                 OR EXISTS(SELECT 1 FROM sales_invoices WHERE branch_id = ? AND deleted_at IS NULL)',
            [$branchId, $branchId, $branchId]
        );
    }

    private static function form(?array $branch): void
    {
        $old = Session::pull('_old', []);
        $values = $old ?: ($branch ?? []);

        Response::view('branches/form', [
            'title'     => ($branch ? 'Edit branch' : 'Add branch'),
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_errors', []),
            'branch'    => $branch,
            'values'    => $values,
            'employees' => $branch !== null
                ? Database::fetchAll("SELECT id, name, employee_code FROM employees WHERE branch_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY name", [$branch['id']])
                : [],
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function validated(?array $existing): array
    {
        $data = [
            'branch_code'   => mb_strtoupper(Request::input('branch_code', 20)),
            'name'          => Request::input('name', 120),
            'address'       => Request::input('address', 255) ?: null,
            'city'          => Request::input('city', 80) ?: null,
            'state'         => Request::input('state', 80) ?: null,
            'pincode'       => Request::input('pincode', 10) ?: null,
            'contact_number'=> Request::input('contact_number', 20) ?: null,
            'email'         => mb_strtolower(Request::input('email', 150)) ?: null,
            'manager_employee_id' => (int) Request::input('manager_employee_id', 10) ?: null,
        ];

        $v = (new Validator())
            ->required('branch_code', $data['branch_code'], 'Branch code')->maxLength('branch_code', $data['branch_code'], 20, 'Branch code')
            ->pattern('branch_code', $data['branch_code'], '/^[A-Z0-9][A-Z0-9_-]{0,19}$/', 'Branch code: letters, digits, dash or underscore.')
            ->required('name', $data['name'], 'Branch name')->maxLength('name', $data['name'], 120, 'Branch name')
            ->maxLength('address', (string) $data['address'], 255, 'Address')
            ->maxLength('pincode', (string) $data['pincode'], 10, 'Pincode');
        if ($data['contact_number']) {
            $v->mobile('contact_number', $data['contact_number'], 'Contact number');
        }
        if ($data['email']) {
            $v->email('email', $data['email']);
        }
        if ($data['pincode'] && !preg_match('/^\d{6}$/', $data['pincode'])) {
            $v->add('pincode', 'Pincode must be 6 digits.');
        }

        $excludeId = $existing['id'] ?? 0;
        if ($data['branch_code'] !== '' && Database::value('SELECT 1 FROM branches WHERE branch_code = ? AND id <> ?', [$data['branch_code'], $excludeId])) {
            $v->add('branch_code', 'This branch code is already used (possibly by a deleted branch).');
        }
        if ($data['name'] !== '' && Database::value('SELECT 1 FROM branches WHERE name = ? AND id <> ?', [$data['name'], $excludeId])) {
            $v->add('name', 'A branch with this name already exists (possibly deleted).');
        }
        if ($data['manager_employee_id'] !== null && $existing !== null) {
            $valid = Database::value('SELECT 1 FROM employees WHERE id = ? AND branch_id = ? AND deleted_at IS NULL', [$data['manager_employee_id'], $existing['id']]);
            if (!$valid) {
                $v->add('manager_employee_id', 'Choose an employee of this branch.');
            }
        }

        return [$data, $v->errors()];
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
