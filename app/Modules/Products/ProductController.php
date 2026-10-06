<?php

declare(strict_types=1);

namespace App\Modules\Products;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/**
 * Product master: Code, Name, Category, Unit, HSN, Rate, GST %, Status.
 * Products are company-wide master data (no branch / employee scope).
 */
final class ProductController
{
    private const PER_PAGE = 25;
    private const GST_RATES = ['0', '0.25', '3', '5', '12', '18', '28'];

    public static function index(): void
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
        $category = mb_substr(trim((string) ($_GET['category'] ?? '')), 0, 80);
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.product_code LIKE ? OR p.hsn_code LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($category !== '') {
            $where[] = 'p.category = ?';
            $params[] = $category;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $products = Database::fetchAll(
            "SELECT p.*,
                    (SELECT COALESCE(SUM(v.taxable_value), 0) FROM v_sales_lines v
                     WHERE v.product_id = p.id AND v.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)) AS sales_12m
             FROM products p WHERE {$whereSql}
             ORDER BY p.status = 'active' DESC, p.name
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        Response::view('products/index', [
            'title'      => 'Products',
            'flash'      => Session::takeFlash(),
            'products'   => $products,
            'filters'    => ['q' => $q, 'status' => $status, 'category' => $category],
            'categories' => Database::query("SELECT DISTINCT category FROM products WHERE deleted_at IS NULL AND category IS NOT NULL ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN),
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'canAdd'     => Gate::allows('products.add'),
            'canEdit'    => Gate::allows('products.edit'),
            'canDelete'  => Gate::allows('products.delete'),
            'canExport'  => Gate::allows('products.export'),
        ]);
    }

    public static function create(): void
    {
        self::form(null);
    }

    public static function edit(array $p): void
    {
        $product = self::find((int) $p['id']);
        if ($product !== null) {
            self::form($product);
        }
    }

    public static function store(): void
    {
        [$data, $errors] = self::validated(null);
        if ($errors) {
            self::back('/products/new', $errors);
            return;
        }
        Database::query(
            "INSERT INTO products (product_code, name, category, unit, hsn_code, rate, gst_rate, status, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)",
            [$data['product_code'], $data['name'], $data['category'], $data['unit'], $data['hsn_code'], $data['rate'], $data['gst_rate'], Auth::id(), Auth::id()]
        );
        $id = (int) Database::connection()->lastInsertId();
        Audit::log('product.created', 'products', $id, null, $data);
        Session::flash('success', "Product {$data['name']} created.");
        Response::redirect('/products');
    }

    public static function update(array $p): void
    {
        $product = self::find((int) $p['id']);
        if ($product === null) {
            return;
        }
        [$data, $errors] = self::validated($product);
        if ($errors) {
            self::back("/products/{$product['id']}/edit", $errors);
            return;
        }
        $old = array_intersect_key($product, $data);
        Database::query(
            'UPDATE products SET product_code = ?, name = ?, category = ?, unit = ?, hsn_code = ?, rate = ?, gst_rate = ?, updated_by = ? WHERE id = ?',
            [$data['product_code'], $data['name'], $data['category'], $data['unit'], $data['hsn_code'], $data['rate'], $data['gst_rate'], Auth::id(), $product['id']]
        );
        $changes = array_diff_assoc(array_map('strval', array_filter($data, static fn ($v) => $v !== null)), array_map('strval', array_filter($old, static fn ($v) => $v !== null)));
        if ($changes) {
            Audit::log('product.updated', 'products', (int) $product['id'], array_intersect_key($old, $changes), $changes);
        }
        Session::flash('success', "Product {$data['name']} updated.");
        Response::redirect('/products');
    }

    public static function toggleStatus(array $p): void
    {
        $product = self::find((int) $p['id']);
        if ($product === null) {
            return;
        }
        $new = $product['status'] === 'active' ? 'inactive' : 'active';
        Database::query('UPDATE products SET status = ?, updated_by = ? WHERE id = ?', [$new, Auth::id(), $product['id']]);
        Audit::log('product.status_changed', 'products', (int) $product['id'], ['status' => $product['status']], ['status' => $new]);
        Session::flash('success', "{$product['name']} is now {$new}" . ($new === 'inactive' ? ' and can no longer be chosen on new entries.' : '.'));
        Response::redirect('/products');
    }

    public static function destroy(array $p): void
    {
        $product = self::find((int) $p['id']);
        if ($product === null) {
            return;
        }
        $used = (bool) Database::value(
            'SELECT EXISTS(SELECT 1 FROM sales_invoice_items WHERE product_id = ?)
                 OR EXISTS(SELECT 1 FROM pending_order_items WHERE product_id = ?)
                 OR EXISTS(SELECT 1 FROM sample_items WHERE product_id = ?)
                 OR EXISTS(SELECT 1 FROM dc_items WHERE product_id = ?)',
            array_fill(0, 4, $product['id'])
        );
        if ($used) {
            Session::flash('error', "{$product['name']} is used on invoices, orders, samples or DCs and cannot be deleted. Mark it inactive instead.");
            Response::redirect('/products');
            return;
        }
        Database::query('UPDATE products SET deleted_at = NOW(), deleted_by = ?, status = ? WHERE id = ?', [Auth::id(), 'inactive', $product['id']]);
        Audit::log('product.deleted', 'products', (int) $product['id'], ['product_code' => $product['product_code'], 'name' => $product['name']]);
        Session::flash('success', "Product {$product['name']} deleted.");
        Response::redirect('/products');
    }

    public static function export(): void
    {
        $rows = Database::fetchAll('SELECT product_code, name, category, unit, hsn_code, rate, gst_rate, status FROM products WHERE deleted_at IS NULL ORDER BY name');
        Audit::log('report.exported', 'products', null, null, ['report' => 'products', 'rows' => count($rows)]);
        Csv::download('products_' . date('Y-m-d') . '.csv', ['Code', 'Name', 'Category', 'Unit', 'HSN', 'Rate', 'GST %', 'Status'],
            array_map(static fn (array $r): array => array_values($r), $rows));
    }

    // -------------------------------------------------------------------------

    private static function find(int $id): ?array
    {
        $product = Database::fetch('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($product === null) {
            Response::error(404, 'Product not found.');
        }
        return $product;
    }

    private static function form(?array $product): void
    {
        $old = Session::pull('_old', []);
        Response::view('products/form', [
            'title'      => $product ? 'Edit product' : 'Add product',
            'flash'      => Session::takeFlash(),
            'errors'     => Session::pull('_errors', []),
            'product'    => $product,
            'values'     => $old ?: ($product ?? ['unit' => 'Nos', 'gst_rate' => '18']),
            'gstRates'   => self::GST_RATES,
            'categories' => Database::query("SELECT DISTINCT category FROM products WHERE deleted_at IS NULL AND category IS NOT NULL ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function validated(?array $existing): array
    {
        $data = [
            'product_code' => mb_strtoupper(Request::input('product_code', 40)),
            'name'         => Request::input('name', 150),
            'category'     => Request::input('category', 80) ?: null,
            'unit'         => Request::input('unit', 20) ?: 'Nos',
            'hsn_code'     => Request::input('hsn_code', 12) ?: null,
            'rate'         => Request::input('rate', 20),
            'gst_rate'     => Request::input('gst_rate', 6),
        ];

        $v = (new Validator())
            ->required('product_code', $data['product_code'], 'Product code')
            ->pattern('product_code', $data['product_code'], '/^[A-Z0-9][A-Z0-9\/_.-]{0,39}$/', 'Product code: letters, digits, / _ . or -.')
            ->required('name', $data['name'], 'Product name')->maxLength('name', $data['name'], 150, 'Product name');
        if ($data['hsn_code'] !== null && !preg_match('/^\d{4,8}$/', $data['hsn_code'])) {
            $v->add('hsn_code', 'HSN code must be 4 to 8 digits.');
        }

        $rate = Money::parse($data['rate'] === '' ? '0' : $data['rate']);
        if ($rate === null) {
            $v->add('rate', 'Rate must be an amount like 1150 or 1150.50.');
        } else {
            $data['rate'] = Money::toDecimal($rate);
        }
        $gst = rtrim(rtrim($data['gst_rate'], '0'), '.');
        $gst = $gst === '' ? '0' : $gst;
        if (!in_array($gst, self::GST_RATES, true)) {
            $v->add('gst_rate', 'Choose a valid GST rate.');
        } else {
            $data['gst_rate'] = $gst;
        }

        $excludeId = $existing['id'] ?? 0;
        if ($data['product_code'] !== '' && Database::value('SELECT 1 FROM products WHERE product_code = ? AND id <> ?', [$data['product_code'], $excludeId])) {
            // Unique key covers deleted products too, so a deleted product's code stays reserved.
            $v->add('product_code', 'This product code is already used (possibly by a deleted product).');
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
