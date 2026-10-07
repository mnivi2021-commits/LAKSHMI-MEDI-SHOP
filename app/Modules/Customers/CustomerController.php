<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\DataScope;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NumberSequence;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/**
 * Customer master: Code, Name, Company, Mobile, Email, Address, City, State,
 * Pincode, GSTIN, Branch, Assigned Sales Employee, Credit Days/Limit, Status.
 * Customer codes (CUS-00001...) are allocated automatically.
 */
final class CustomerController
{
    private const PER_PAGE = 20;
    private const STATUSES = ['active', 'inactive', 'blocked'];

    public static function index(): void
    {
        $user = Auth::user();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = in_array($_GET['status'] ?? '', self::STATUSES, true) ? $_GET['status'] : '';
        $branchId = (int) ($_GET['branch'] ?? 0) ?: null;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        [$scopeSql, $params] = DataScope::for($user)->where('c.branch_id', 'c.employee_id');
        $where = ['c.deleted_at IS NULL', $scopeSql];
        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.customer_code LIKE ? OR c.company_name LIKE ? OR c.mobile LIKE ? OR c.gstin LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($branchId !== null) {
            $where[] = 'c.branch_id = ?';
            $params[] = $branchId;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) FROM customers c WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $customers = Database::fetchAll(
            "SELECT c.*, b.branch_code, e.short_name AS employee_short, e.name AS employee_name
             FROM customers c JOIN branches b ON b.id = c.branch_id LEFT JOIN employees e ON e.id = c.employee_id
             WHERE {$whereSql}
             ORDER BY c.status = 'active' DESC, c.name
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        [$bScopeSql, $bParams] = DataScope::for($user)->branchListWhere('id');
        Response::view('customers/index', [
            'title'     => 'Customers',
            'flash'     => Session::takeFlash(),
            'customers' => $customers,
            'filters'   => ['q' => $q, 'status' => $status, 'branch' => $branchId],
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'branches'  => Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bScopeSql} ORDER BY name", $bParams),
            'canAdd'    => Gate::allows('customers.add'),
            'canEdit'   => Gate::allows('customers.edit'),
            'canDelete' => Gate::allows('customers.delete'),
            'canExport' => Gate::allows('customers.export'),
        ]);
    }

    public static function create(): void
    {
        self::form(null);
    }

    public static function show(array $p): void
    {
        $customer = self::findInScope((int) $p['id']);
        if ($customer === null) {
            return;
        }

        $sales = (int) Database::value(
            "SELECT COALESCE(SUM(CASE document_type WHEN 'credit_note' THEN -taxable_amount ELSE taxable_amount END), 0)
             FROM sales_invoices WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
            [$customer['id']]
        );
        $lastOrder = Database::value("SELECT MAX(invoice_date) FROM sales_invoices WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL", [$customer['id']]);
        $lastPayment = Database::value("SELECT MAX(receipt_date) FROM collections WHERE customer_id = ? AND status IN ('received','cleared') AND deleted_at IS NULL", [$customer['id']]);
        $outstanding = (int) Database::value('SELECT COALESCE(SUM(balance), 0) FROM v_invoice_balances WHERE customer_id = ?', [$customer['id']]);

        Response::view('customers/show', [
            'title'       => $customer['name'] . ' · Customer',
            'flash'       => Session::takeFlash(),
            'customer'    => $customer,
            'sales'       => $sales,
            'lastOrder'   => $lastOrder,
            'lastPayment' => $lastPayment,
            'outstanding' => $outstanding,
            'canEdit'     => Gate::allows('customers.edit'),
        ]);
    }

    public static function edit(array $p): void
    {
        $customer = self::findInScope((int) $p['id']);
        if ($customer !== null) {
            self::form($customer);
        }
    }

    public static function store(): void
    {
        [$data, $errors] = self::validated(null);
        if ($errors) {
            self::back('/customers/new', $errors);
            return;
        }

        $id = Database::transaction(static function () use ($data): int {
            $code = NumberSequence::next('customer');
            Database::query(
                "INSERT INTO customers (customer_code, name, company_name, mobile, alternate_mobile, email, address, city, state,
                                        pincode, gstin, branch_id, employee_id, credit_days, credit_limit, status, sms_opt_out, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)",
                [$code, $data['name'], $data['company_name'], $data['mobile'], $data['alternate_mobile'], $data['email'],
                 $data['address'], $data['city'], $data['state'], $data['pincode'], $data['gstin'], $data['branch_id'],
                 $data['employee_id'], $data['credit_days'], $data['credit_limit'], $data['sms_opt_out'], Auth::id(), Auth::id()]
            );
            return (int) Database::connection()->lastInsertId();
        });

        Audit::log('customer.created', 'customers', $id, null, $data);
        Session::flash('success', "Customer {$data['name']} created.");
        Response::redirect("/customers/{$id}");
    }

    public static function update(array $p): void
    {
        $customer = self::findInScope((int) $p['id']);
        if ($customer === null) {
            return;
        }

        [$data, $errors] = self::validated($customer);
        if ($errors) {
            self::back("/customers/{$customer['id']}/edit", $errors);
            return;
        }

        $old = array_intersect_key($customer, $data);
        Database::query(
            'UPDATE customers SET name = ?, company_name = ?, mobile = ?, alternate_mobile = ?, email = ?, address = ?,
                                  city = ?, state = ?, pincode = ?, gstin = ?, branch_id = ?, employee_id = ?,
                                  credit_days = ?, credit_limit = ?, sms_opt_out = ?, updated_by = ? WHERE id = ?',
            [$data['name'], $data['company_name'], $data['mobile'], $data['alternate_mobile'], $data['email'], $data['address'],
             $data['city'], $data['state'], $data['pincode'], $data['gstin'], $data['branch_id'], $data['employee_id'],
             $data['credit_days'], $data['credit_limit'], $data['sms_opt_out'], Auth::id(), $customer['id']]
        );

        $changes = array_diff_assoc($data, $old);
        if ($changes) {
            Audit::log('customer.updated', 'customers', (int) $customer['id'], array_intersect_key($old, $changes), $changes);
        }
        Session::flash('success', "Customer {$data['name']} updated.");
        Response::redirect("/customers/{$customer['id']}");
    }

    public static function setStatus(array $p): void
    {
        $customer = self::findInScope((int) $p['id']);
        if ($customer === null) {
            return;
        }
        $status = Request::input('status', 10);
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Choose a valid status.');
            Response::redirect('/customers');
            return;
        }

        Database::query('UPDATE customers SET status = ?, updated_by = ? WHERE id = ?', [$status, Auth::id(), $customer['id']]);
        Audit::log('customer.status_changed', 'customers', (int) $customer['id'], ['status' => $customer['status']], ['status' => $status]);
        Session::flash('success', "{$customer['name']} marked " . $status . '.');
        Response::redirect('/customers');
    }

    public static function destroy(array $p): void
    {
        $customer = self::findInScope((int) $p['id']);
        if ($customer === null) {
            return;
        }
        $hasTransactions = (bool) Database::value(
            'SELECT EXISTS(SELECT 1 FROM sales_invoices WHERE customer_id = ? AND deleted_at IS NULL)
                 OR EXISTS(SELECT 1 FROM collections WHERE customer_id = ? AND deleted_at IS NULL)
                 OR EXISTS(SELECT 1 FROM pending_orders WHERE customer_id = ? AND deleted_at IS NULL)',
            [$customer['id'], $customer['id'], $customer['id']]
        );
        if ($hasTransactions) {
            Session::flash('error', "{$customer['name']} has transactions on record and cannot be deleted. Mark it inactive instead.");
            Response::redirect('/customers');
            return;
        }

        Database::query('UPDATE customers SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [Auth::id(), $customer['id']]);
        Audit::log('customer.deleted', 'customers', (int) $customer['id'], ['customer_code' => $customer['customer_code'], 'name' => $customer['name']]);
        Session::flash('success', "Customer {$customer['name']} deleted.");
        Response::redirect('/customers');
    }

    public static function export(): void
    {
        $user = Auth::user();
        [$scopeSql, $params] = DataScope::for($user)->where('c.branch_id', 'c.employee_id');
        $rows = Database::fetchAll(
            "SELECT c.customer_code, c.name, c.company_name, c.mobile, c.email, c.city, c.state, c.pincode, c.gstin,
                    b.branch_code, e.short_name AS employee, c.credit_days, c.credit_limit, c.status
             FROM customers c JOIN branches b ON b.id = c.branch_id LEFT JOIN employees e ON e.id = c.employee_id
             WHERE c.deleted_at IS NULL AND {$scopeSql} ORDER BY c.name",
            $params
        );
        Audit::log('report.exported', 'customers', null, null, ['report' => 'customers', 'rows' => count($rows)]);

        Csv::download('customers_' . date('Y-m-d') . '.csv',
            ['Code', 'Name', 'Company', 'Mobile', 'Email', 'City', 'State', 'Pincode', 'GSTIN', 'Branch', 'Employee', 'Credit Days', 'Credit Limit', 'Status'],
            array_map(static fn (array $r): array => array_values($r), $rows));
    }

    /** GET /customers/lookup?q= - small scoped search used by other modules' pickers. */
    public static function lookup(): void
    {
        $user = Auth::user();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        [$scopeSql, $params] = DataScope::for($user)->where('c.branch_id', 'c.employee_id');
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = Database::fetchAll(
            "SELECT c.id, c.customer_code, c.name FROM customers c
             WHERE c.deleted_at IS NULL AND c.status <> 'blocked' AND {$scopeSql} AND (c.name LIKE ? OR c.customer_code LIKE ?)
             ORDER BY c.name LIMIT 20",
            array_merge($params, [$like, $like])
        );
        Response::json(['success' => true, 'items' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'label' => $r['name'], 'meta' => $r['customer_code']], $rows)]);
    }

    // -------------------------------------------------------------------------

    private static function findInScope(int $id): ?array
    {
        [$scopeSql, $params] = DataScope::for(Auth::user())->where('c.branch_id', 'c.employee_id');
        $customer = Database::fetch(
            "SELECT c.* FROM customers c WHERE c.id = ? AND c.deleted_at IS NULL AND {$scopeSql}",
            array_merge([$id], $params)
        );
        if ($customer === null) {
            Response::error(404, 'Customer not found.');
        }
        return $customer;
    }

    private static function form(?array $customer): void
    {
        $old = Session::pull('_old', []);
        $values = $old ?: ($customer ?? ['credit_days' => 30]);
        $user = Auth::user();

        [$bScopeSql, $bParams] = DataScope::for($user)->branchListWhere('id');
        $branches = Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bScopeSql} ORDER BY name", $bParams);

        Response::view('customers/form', [
            'title'    => $customer ? 'Edit customer' : 'Add customer',
            'flash'    => Session::takeFlash(),
            'errors'   => Session::pull('_errors', []),
            'customer' => $customer,
            'values'   => $values,
            'branches' => $branches,
            'employees'=> Database::fetchAll("SELECT id, name, employee_code, branch_id FROM employees WHERE deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1 ORDER BY name"),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function validated(?array $existing): array
    {
        $data = [
            'name'            => Request::input('name', 150),
            'company_name'    => Request::input('company_name', 150) ?: null,
            'mobile'          => Request::input('mobile', 20) ?: null,
            'alternate_mobile'=> Request::input('alternate_mobile', 20) ?: null,
            'email'           => mb_strtolower(Request::input('email', 150)) ?: null,
            'address'         => Request::input('address', 255) ?: null,
            'city'            => Request::input('city', 80) ?: null,
            'state'           => Request::input('state', 80) ?: null,
            'pincode'         => Request::input('pincode', 10) ?: null,
            'gstin'           => mb_strtoupper(Request::input('gstin', 15)) ?: null,
            'branch_id'       => (int) Request::input('branch_id', 10) ?: null,
            'employee_id'     => (int) Request::input('employee_id', 10) ?: null,
            'credit_days'     => (int) Request::input('credit_days', 5) ?: 30,
            'credit_limit'    => Request::input('credit_limit', 15),   // normalised to a DECIMAL string below once validated
            'sms_opt_out'     => Request::input('sms_opt_out', 1) === '1' ? 1 : 0,
        ];

        $v = (new Validator())
            ->required('name', $data['name'], 'Customer name')->maxLength('name', $data['name'], 150, 'Customer name')
            ->maxLength('company_name', (string) $data['company_name'], 150, 'Company name')
            ->maxLength('address', (string) $data['address'], 255, 'Address');
        if ($data['mobile']) {
            $v->mobile('mobile', $data['mobile']);
        }
        if ($data['alternate_mobile']) {
            $v->mobile('alternate_mobile', $data['alternate_mobile'], 'Alternate mobile');
        }
        if ($data['email']) {
            $v->email('email', $data['email']);
        }
        if (!$data['mobile'] && !$data['email']) {
            $v->add('mobile', 'Provide a mobile number or an email address.');
        }
        if ($data['pincode'] && !preg_match('/^\d{6}$/', $data['pincode'])) {
            $v->add('pincode', 'Pincode must be 6 digits.');
        }
        if ($data['gstin'] && !preg_match('/^[0-9]{2}[A-Z0-9]{10}[0-9][A-Z][0-9A-Z]$/', $data['gstin'])) {
            $v->add('gstin', 'GSTIN must be 15 characters in the standard format.');
        }
        if ($data['credit_days'] < 0 || $data['credit_days'] > 365) {
            $v->add('credit_days', 'Credit days must be between 0 and 365.');
        }
        if ($data['credit_limit'] === '') {
            $data['credit_limit'] = null;
        } else {
            $paise = Money::parse($data['credit_limit']);
            if ($paise === null) {
                $v->add('credit_limit', 'Credit limit must be a positive amount, e.g. 50000 or 50000.00.');
            } else {
                $data['credit_limit'] = Money::toDecimal($paise);
            }
        }

        $branch = $data['branch_id'] ? Database::fetch("SELECT id FROM branches WHERE id = ? AND deleted_at IS NULL AND status = 'active'", [$data['branch_id']]) : null;
        if ($branch === null) {
            $v->add('branch_id', 'Choose a branch.');
        } elseif (!DataScope::for(Auth::user())->allowsBranch((int) $branch['id'])) {
            $v->add('branch_id', 'You do not have access to this branch.');
        }
        if ($data['employee_id'] !== null) {
            $emp = Database::fetch("SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1", [$data['employee_id']]);
            if ($emp === null) {
                $v->add('employee_id', 'Choose a valid sales employee.');
            }
        }

        if ($data['mobile'] !== null) {
            $excludeId = $existing['id'] ?? 0;
            $dup = Database::value('SELECT 1 FROM customers WHERE mobile = ? AND id <> ? AND deleted_at IS NULL', [$data['mobile'], $excludeId]);
            if ($dup) {
                $v->add('mobile', 'Another customer already uses this mobile number.');
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
