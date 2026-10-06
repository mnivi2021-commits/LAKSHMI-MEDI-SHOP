<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\HealthCheck;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Modules\Auth\ApiAuthController;
use App\Modules\Access\RoleController;
use App\Modules\Access\UserController;
use App\Modules\Auth\AuthController;
use App\Modules\Branches\BranchController;
use App\Modules\Customers\CustomerController;
use App\Modules\Hrm\EmployeeController;
use App\Modules\Imports\ImportController;
use App\Modules\Hrm\MasterController;
use App\Modules\Leads\LeadController;
use App\Modules\Products\ProductController;
use App\Modules\Sales\SalesDetailsController;
use App\Modules\Dashboard\CollectionController;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Dashboard\OutstandingController;
use App\Modules\Dashboard\PendingOrderController;
use App\Modules\Dashboard\SalesController;

/** @var Router $router */

// -----------------------------------------------------------------------------
// Public intro page / signed-in home
// -----------------------------------------------------------------------------
$router->get('/', static function (): void {
    if (!Auth::check()) {
        Response::view('intro', ['title' => 'Marketing CRM']);
        return;
    }
    if (Auth::user()['must_change_password']) {
        Response::redirect('/password/change');
        return;
    }
    DashboardController::index();     // dashboard, or a simple home page without dashboard.view
});

// Dashboard quick-add (JSON, session + CSRF header) and scope-limited lookups
$router->post('/dashboard/add/{type}', [DashboardController::class, 'quickAdd'], ['auth', 'csrf']);
$router->get('/dashboard/lookup/{type}', [DashboardController::class, 'lookup'], ['auth', 'can:dashboard.view']);

// Step A1 - Sales Performance drill-down, export, mobile API
$router->get('/dashboard/sales', [SalesController::class, 'detail'], ['auth', 'can:dashboard.view', 'can:sales.view']);
$router->get('/dashboard/sales/export', [SalesController::class, 'export'], ['auth', 'can:sales.view', 'can:sales.export']);
$router->get('/api/dashboard/sales', [SalesController::class, 'api'], ['api_auth', 'can:dashboard.view']);

// Step A2 - Payment Collection
$router->get('/dashboard/collection', [CollectionController::class, 'detail'], ['auth', 'can:dashboard.view', 'can:collections.view']);
$router->get('/dashboard/collection/export', [CollectionController::class, 'export'], ['auth', 'can:collections.view', 'can:collections.export']);
$router->get('/api/dashboard/collection', [CollectionController::class, 'api'], ['api_auth', 'can:dashboard.view']);

// Step A3 - Branch Pending Order
$router->get('/dashboard/pending', [PendingOrderController::class, 'detail'], ['auth', 'can:dashboard.view', 'can:pending_orders.view']);
$router->get('/dashboard/pending/export', [PendingOrderController::class, 'export'], ['auth', 'can:pending_orders.view', 'can:pending_orders.export']);
$router->get('/api/dashboard/pending', [PendingOrderController::class, 'api'], ['api_auth', 'can:dashboard.view']);

// 90 / 150 Day Outstanding
$router->get('/dashboard/outstanding', [OutstandingController::class, 'detail'], ['auth', 'can:dashboard.view', 'can:outstanding.view']);
$router->get('/dashboard/outstanding/export', [OutstandingController::class, 'export'], ['auth', 'can:outstanding.view', 'can:outstanding.export']);
$router->get('/api/dashboard/outstanding', [OutstandingController::class, 'api'], ['api_auth', 'can:dashboard.view']);

// -----------------------------------------------------------------------------
// Authentication (web)
// -----------------------------------------------------------------------------
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);

$router->post('/logout', static function (): void {
    if (!Auth::check()) {                 // session already gone - nothing to protect
        Response::redirect('/login');
        return;
    }
    if (Csrf::enforce()) {
        AuthController::logout();
    }
});

$router->get('/password/change', [AuthController::class, 'showChangePassword'], ['auth']);
$router->post('/password/change', [AuthController::class, 'changePassword'], ['auth', 'csrf']);

// -----------------------------------------------------------------------------
// Access: users, roles, permission matrix
// -----------------------------------------------------------------------------
$router->get('/access/users', [UserController::class, 'index'], ['auth', 'can:users.view']);
$router->get('/access/users/new', [UserController::class, 'create'], ['auth', 'can:users.add']);
$router->post('/access/users', [UserController::class, 'store'], ['auth', 'can:users.add', 'csrf']);
$router->get('/access/users/{id}/edit', [UserController::class, 'edit'], ['auth', 'can:users.edit']);
$router->post('/access/users/{id}', [UserController::class, 'update'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/status', [UserController::class, 'toggleStatus'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/reset-password', [UserController::class, 'resetPassword'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/unlock', [UserController::class, 'unlock'], ['auth', 'can:users.edit', 'csrf']);
$router->post('/access/users/{id}/delete', [UserController::class, 'destroy'], ['auth', 'can:users.delete', 'csrf']);
$router->get('/access/users/{id}/permissions', [UserController::class, 'permissions'], ['auth', 'can:access.manage']);
$router->post('/access/users/{id}/permissions', [UserController::class, 'savePermissions'], ['auth', 'can:access.manage', 'csrf']);

$router->get('/access/roles', [RoleController::class, 'index'], ['auth', 'can:access.manage']);
$router->get('/access/roles/new', [RoleController::class, 'create'], ['auth', 'can:access.manage']);
$router->post('/access/roles', [RoleController::class, 'store'], ['auth', 'can:access.manage', 'csrf']);
$router->get('/access/roles/{id}', [RoleController::class, 'edit'], ['auth', 'can:access.manage']);
$router->post('/access/roles/{id}', [RoleController::class, 'update'], ['auth', 'can:access.manage', 'csrf']);
$router->post('/access/roles/{id}/delete', [RoleController::class, 'destroy'], ['auth', 'can:access.manage', 'csrf']);

// -----------------------------------------------------------------------------
// Branch Details
// -----------------------------------------------------------------------------
$router->get('/branches', [BranchController::class, 'index'], ['auth', 'can:branches.view']);
$router->get('/branches/new', [BranchController::class, 'create'], ['auth', 'can:branches.add']);
$router->post('/branches', [BranchController::class, 'store'], ['auth', 'can:branches.add', 'csrf']);
$router->get('/branches/{id}/edit', [BranchController::class, 'edit'], ['auth', 'can:branches.view']);
$router->post('/branches/{id}', [BranchController::class, 'update'], ['auth', 'can:branches.edit', 'csrf']);
$router->post('/branches/{id}/status', [BranchController::class, 'toggleStatus'], ['auth', 'can:branches.edit', 'csrf']);
$router->post('/branches/{id}/delete', [BranchController::class, 'destroy'], ['auth', 'can:branches.delete', 'csrf']);
$router->get('/branches/export', [BranchController::class, 'export'], ['auth', 'can:branches.view', 'can:branches.export']);

// -----------------------------------------------------------------------------
// Customers
// -----------------------------------------------------------------------------
$router->get('/customers', [CustomerController::class, 'index'], ['auth', 'can:customers.view']);
$router->get('/customers/new', [CustomerController::class, 'create'], ['auth', 'can:customers.add']);
$router->post('/customers', [CustomerController::class, 'store'], ['auth', 'can:customers.add', 'csrf']);
$router->get('/customers/export', [CustomerController::class, 'export'], ['auth', 'can:customers.view', 'can:customers.export']);
$router->get('/customers/lookup', [CustomerController::class, 'lookup'], ['auth', 'can:customers.view']);
$router->get('/customers/{id}', [CustomerController::class, 'show'], ['auth', 'can:customers.view']);
$router->get('/customers/{id}/edit', [CustomerController::class, 'edit'], ['auth', 'can:customers.edit']);
$router->post('/customers/{id}', [CustomerController::class, 'update'], ['auth', 'can:customers.edit', 'csrf']);
$router->post('/customers/{id}/status', [CustomerController::class, 'setStatus'], ['auth', 'can:customers.edit', 'csrf']);
$router->post('/customers/{id}/delete', [CustomerController::class, 'destroy'], ['auth', 'can:customers.delete', 'csrf']);

// -----------------------------------------------------------------------------
// Products
// -----------------------------------------------------------------------------
$router->get('/products', [ProductController::class, 'index'], ['auth', 'can:products.view']);
$router->get('/products/new', [ProductController::class, 'create'], ['auth', 'can:products.add']);
$router->post('/products', [ProductController::class, 'store'], ['auth', 'can:products.add', 'csrf']);
$router->get('/products/export', [ProductController::class, 'export'], ['auth', 'can:products.view', 'can:products.export']);
$router->get('/products/{id}/edit', [ProductController::class, 'edit'], ['auth', 'can:products.edit']);
$router->post('/products/{id}', [ProductController::class, 'update'], ['auth', 'can:products.edit', 'csrf']);
$router->post('/products/{id}/status', [ProductController::class, 'toggleStatus'], ['auth', 'can:products.edit', 'csrf']);
$router->post('/products/{id}/delete', [ProductController::class, 'destroy'], ['auth', 'can:products.delete', 'csrf']);

// -----------------------------------------------------------------------------
// Leads
// -----------------------------------------------------------------------------
$router->get('/leads', [LeadController::class, 'index'], ['auth', 'can:leads.view']);
$router->get('/leads/new', [LeadController::class, 'create'], ['auth', 'can:leads.add']);
$router->post('/leads', [LeadController::class, 'store'], ['auth', 'can:leads.add', 'csrf']);
$router->get('/leads/export', [LeadController::class, 'export'], ['auth', 'can:leads.view', 'can:leads.export']);
$router->get('/leads/{id}', [LeadController::class, 'show'], ['auth', 'can:leads.view']);
$router->get('/leads/{id}/edit', [LeadController::class, 'edit'], ['auth', 'can:leads.edit']);
$router->post('/leads/{id}', [LeadController::class, 'update'], ['auth', 'can:leads.edit', 'csrf']);
$router->post('/leads/{id}/status', [LeadController::class, 'changeStatus'], ['auth', 'can:leads.edit', 'csrf']);
$router->post('/leads/{id}/followups', [LeadController::class, 'addFollowup'], ['auth', 'can:leads.edit', 'csrf']);
$router->post('/leads/{id}/followups/{fid}/complete', [LeadController::class, 'completeFollowup'], ['auth', 'can:leads.edit', 'csrf']);
$router->post('/leads/{id}/convert', [LeadController::class, 'convert'], ['auth', 'can:leads.edit', 'csrf']);
$router->post('/leads/{id}/delete', [LeadController::class, 'destroy'], ['auth', 'can:leads.delete', 'csrf']);

// -----------------------------------------------------------------------------
// Sales Details (combined grid by employee / branch / month)
// -----------------------------------------------------------------------------
$salesAny = 'can_any:sales.view,targets.view,collections.view,pending_orders.view,samples.view,dc.view,outstanding.view';
$router->get('/sales', [SalesDetailsController::class, 'index'], ['auth', $salesAny]);
$router->get('/sales/export', [SalesDetailsController::class, 'export'], ['auth', 'can:sales.export']);
$router->get('/sales/documents/{kind}', [SalesDetailsController::class, 'documents'], ['auth']);
$router->get('/api/sales/grid', [SalesDetailsController::class, 'api'], ['api_auth', $salesAny]);

// -----------------------------------------------------------------------------
// Excel Upload (literal segments before {id})
// -----------------------------------------------------------------------------
$router->get('/imports', [ImportController::class, 'index'], ['auth', ImportController::ANY_PERMISSION]);
$router->get('/imports/new/{type}', [ImportController::class, 'create'], ['auth']);
$router->post('/imports/new/{type}', [ImportController::class, 'upload'], ['auth', 'csrf']);
$router->get('/imports/template/{type}', [ImportController::class, 'template'], ['auth']);
$router->get('/imports/{id}', [ImportController::class, 'show'], ['auth']);
$router->get('/imports/{id}/map', [ImportController::class, 'map'], ['auth']);
$router->post('/imports/{id}/map', [ImportController::class, 'saveMap'], ['auth', 'csrf']);
$router->post('/imports/{id}/run', [ImportController::class, 'run'], ['auth', 'csrf']);
$router->post('/imports/{id}/cancel', [ImportController::class, 'cancel'], ['auth', 'csrf']);
$router->get('/imports/{id}/errors', [ImportController::class, 'errors'], ['auth']);

// -----------------------------------------------------------------------------
// HRM: employees, departments, designations
// -----------------------------------------------------------------------------
$router->get('/hrm', [EmployeeController::class, 'index'], ['auth', 'can:hrm.view']);
$router->get('/hrm/new', [EmployeeController::class, 'create'], ['auth', 'can:hrm.add']);
$router->post('/hrm', [EmployeeController::class, 'store'], ['auth', 'can:hrm.add', 'csrf']);
$router->get('/hrm/export', [EmployeeController::class, 'export'], ['auth', 'can:hrm.view', 'can:hrm.export']);
$router->get('/hrm/lists/{type}', [MasterController::class, 'index'], ['auth', 'can:hrm.view']);
$router->post('/hrm/lists/{type}', [MasterController::class, 'store'], ['auth', 'can:hrm.edit', 'csrf']);
$router->post('/hrm/lists/{type}/{id}', [MasterController::class, 'update'], ['auth', 'can:hrm.edit', 'csrf']);
$router->get('/hrm/{id}/edit', [EmployeeController::class, 'edit'], ['auth', 'can:hrm.edit']);
$router->post('/hrm/{id}', [EmployeeController::class, 'update'], ['auth', 'can:hrm.edit', 'csrf']);
$router->post('/hrm/{id}/delete', [EmployeeController::class, 'destroy'], ['auth', 'can:hrm.delete', 'csrf']);

// -----------------------------------------------------------------------------
// Authentication (API / mobile)
// -----------------------------------------------------------------------------
$router->post('/api/auth/login', [ApiAuthController::class, 'login']);
$router->get('/api/auth/me', [ApiAuthController::class, 'me'], ['api_auth']);
$router->post('/api/auth/logout', [ApiAuthController::class, 'logout'], ['api_auth']);

// -----------------------------------------------------------------------------
// Health (detail only on the server itself or when APP_ENV=local)
// -----------------------------------------------------------------------------
$healthDetailAllowed = static fn (): bool => Config::get('app.env') === 'local' || Request::isLocal();

$router->get('/health', static function () use ($healthDetailAllowed): void {
    $report = (new HealthCheck())->run();
    $status = $report['status'] === 'fail' ? 503 : 200;
    if (!$healthDetailAllowed()) {
        Response::view('health', ['report' => ['status' => $report['status']], 'limited' => true], $status);
        return;
    }
    Response::view('health', ['report' => $report, 'limited' => false], $status);
});

$router->get('/api/health', static function () use ($healthDetailAllowed): void {
    $report = (new HealthCheck())->run();
    $payload = $healthDetailAllowed() ? $report : ['status' => $report['status']];
    Response::json(['success' => $report['status'] !== 'fail', 'data' => $payload], $report['status'] === 'fail' ? 503 : 200);
});
