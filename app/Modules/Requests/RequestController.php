<?php

declare(strict_types=1);

namespace App\Modules\Requests;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NumberSequence;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Imports\Importer;
use DateTimeImmutable;
use RuntimeException;

/**
 * Screen B - request forms:
 *   Lead · Enquiry · Order       (leads with record_type, pending_orders)
 *   Sample · DC request          (samples needing manager approval, dc_records with approval mail)
 *
 * The customer is picked from the customer master; a customer who is not in the
 * master is added to it first (for orders, DC and samples), so every document
 * belongs to a real customer record.
 */
final class RequestController
{
    public const TYPES = [
        'lead'    => ['New lead', 'Lead · Enquiry · Order', 'leads.add'],
        'enquiry' => ['Enquiry', 'Lead · Enquiry · Order', 'leads.add'],
        'order'   => ['Order', 'Lead · Enquiry · Order', 'pending_orders.add'],
        'dc'      => ['DC request', 'Sample · DC request', 'dc.add'],
        'sample'  => ['Sample request', 'Sample · DC request', 'samples.add'],
    ];
    public const ORDER_REFS = ['po' => 'Customer PO reference', 'mail' => 'Customer mail', 'phone' => 'Customer phone call', 'advance' => 'Advance payment'];
    public const DC_APPROVALS = ['customer_mail' => 'Customer mail', 'md_approval' => 'M.D approval mail'];
    public const SOURCES = ['mail' => 'Customer request mail', 'office_visit' => 'Direct visit to office'];
    public const LINES = 6;

    // =========================================================================
    // Page: form + recent requests of that type
    // =========================================================================

    public static function index(): void
    {
        $user = Auth::user();
        $allowed = array_filter(self::TYPES, static fn ($t) => Gate::allows($t[2], $user));
        if ($allowed === []) {
            Gate::authorize('leads.add');
            return;
        }
        $type = array_key_exists($_GET['type'] ?? '', $allowed) ? $_GET['type'] : array_key_first($allowed);
        $scope = DataScope::for($user);
        [$bw, $bp] = $scope->branchListWhere('id');
        [$ew, $ep] = $scope->where('branch_id', 'id');
        Response::view('requests/index', [
            'title'     => self::TYPES[$type][0] . ' · Requests',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_req_errors', []),
            'values'    => Session::pull('_req_old', []),
            'type'      => $type,
            'allowed'   => $allowed,
            'branches'  => Database::fetchAll("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bw} ORDER BY name", $bp),
            'employees' => Database::fetchAll("SELECT id, name, short_name, is_sales_rep FROM employees WHERE deleted_at IS NULL AND status = 'active' AND {$ew} ORDER BY name", $ep),
            'recent'    => self::recent($type, $user),
            'canApprove'=> Gate::allows('samples.approve', $user),
            'canNewCustomer' => Gate::allows('customers.add', $user),
            'scripts'   => ['dashboard.js'],
        ]);
    }

    // =========================================================================
    // Save
    // =========================================================================

    public static function store(array $p): void
    {
        $type = (string) ($p['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            Response::error(404, 'Unknown request type.');
            return;
        }
        if (!Gate::authorize(self::TYPES[$type][2])) {
            return;
        }
        $user = Auth::user();
        $in = $_POST;
        $errors = [];
        try {
            $id = Database::transaction(static function () use ($type, $in, $user, &$errors): ?int {
                $form = new RequestForm($user, $in, $errors);
                $id = match ($type) {
                    'lead', 'enquiry' => self::saveLead($type, $form),
                    'order'           => self::saveOrder($form),
                    'dc'              => self::saveDc($form),
                    'sample'          => self::saveSample($form),
                };
                if ($errors) {
                    throw new RuntimeException('validation');           // roll back anything written (e.g. a new customer)
                }
                return $id;
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'validation') {
                throw $e;
            }
            $id = null;
        }
        if ($errors || $id === null) {
            $_SESSION['_req_errors'] = $errors;
            $_SESSION['_req_old'] = $in;
            Session::flash('error', 'Please correct the highlighted fields. Nothing was saved.');
            Response::redirect('/requests?type=' . $type);
            return;
        }
        Session::flash('success', match ($type) {
            'lead'    => 'Lead generated (internal only - nothing was sent to the customer).',
            'enquiry' => 'Enquiry saved and the offer is ready.',
            'order'   => 'Order saved.',
            'dc'      => 'DC request saved.',
            'sample'  => Database::value('SELECT approval_status FROM samples WHERE id = ?', [$id]) === 'approved'
                ? 'Sample request saved and approved.' : 'Sample request saved. It needs a manager\'s approval before it is given out.',
        });
        Response::redirect("/requests/view/{$type}/{$id}");
    }

    private static function saveLead(string $type, RequestForm $f): ?int
    {
        $informed = $f->informed();
        $customer = $f->customer(false);
        $lines = $f->lines(true, $type === 'enquiry' ? 'Offer price' : 'Approx. price');
        $source = null;
        $leadType = $customer['id'] !== null ? 'new_product' : 'new_customer';
        if ($type === 'enquiry') {
            $source = $f->choice('enquiry_source', self::SOURCES, 'Enquiry came by');
            $leadType = $f->choice('lead_type', ['new_customer' => 'New customer', 'new_product' => 'New product'], 'Enquiry for') ?? $leadType;
        }
        if ($f->failed()) {
            return null;
        }
        $number = NumberSequence::next($type === 'enquiry' ? 'enquiry' : 'lead');
        Database::query(
            "INSERT INTO leads (lead_number, record_type, lead_type, name, company_name, contact_person, mobile, email, enquiry_source,
                                branch_id, employee_id, informed_by, informed_employee_id, status, priority, expected_value, remarks,
                                customer_id, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'medium', ?, ?, ?, ?, ?)",
            [$number, $type, $leadType, $customer['name'], $customer['company'], $f->text('contact_person', 150), $customer['mobile'], $customer['email'],
             $source, $customer['branch_id'], $customer['employee_id'], $informed['by'], $informed['employee_id'],
             $type === 'enquiry' ? 'quotation' : 'new', Money::toDecimal(array_sum(array_column($lines, 'amount'))), $f->text('remarks', 2000),
             $customer['id'], $f->uid(), $f->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare('INSERT INTO lead_items (lead_id, product_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['description'], $l['qty'], Money::toDecimal($l['price']), Money::toDecimal($l['amount'])]);
        }
        Audit::log($type . '.created', 'leads', $id, null, ['number' => $number, 'customer' => $customer['name'], 'lines' => count($lines)]);
        return $id;
    }

    private static function saveOrder(RequestForm $f): ?int
    {
        $informed = $f->informed();
        $customer = $f->customer(true);
        $ref = $f->choice('reference_type', self::ORDER_REFS, 'Order came by');
        $detail = $f->text('reference_detail', 255);
        $advance = null;
        if ($ref === 'advance') {
            $advance = $f->money('advance_amount', 'Advance amount', true);
        } elseif ($ref !== null && $detail === null) {
            $f->error('reference_detail', match ($ref) {
                'po' => 'Enter the customer PO number.', 'mail' => 'Enter the mail date / subject.', default => 'Enter who called and when.',
            });
        }
        $date = $f->date('order_date', 'Order date');
        $delivery = $f->date('expected_date', 'Expected delivery', false, false);
        $lines = $f->lines(false, 'Rate');
        $fy = $date ? $f->fy($date, 'order_date') : null;
        if ($f->failed()) {
            return null;
        }
        $no = NumberSequence::next('order');
        Database::query(
            "INSERT INTO pending_orders (financial_year_id, order_no, order_date, customer_po_no, reference_type, reference_detail, advance_amount,
                                         customer_id, branch_id, employee_id, informed_by, informed_employee_id, expected_delivery_date,
                                         status, source, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 'manual', ?, ?, ?)",
            [$fy['id'], $no, $date, $ref === 'po' ? mb_substr((string) $detail, 0, 60) : null, $ref, $detail, $advance !== null ? Money::toDecimal($advance) : null,
             $customer['id'], $customer['branch_id'], $customer['employee_id'], $informed['by'], $informed['employee_id'], $delivery,
             $f->text('remarks', 500), $f->uid(), $f->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare('INSERT INTO pending_order_items (order_id, product_id, order_qty, rate, order_value) VALUES (?, ?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['qty'], Money::toDecimal($l['price']), Money::toDecimal($l['amount'])]);
        }
        Audit::log('pending_order.created', 'pending_orders', $id, null, ['order_no' => $no, 'reference' => $ref, 'source' => 'request form']);
        return $id;
    }

    private static function saveDc(RequestForm $f): ?int
    {
        $customer = $f->customer(true);
        $approval = $f->choice('approval_type', self::DC_APPROVALS, 'Approval');
        $approvalRef = $f->text('approval_reference', 255);
        if ($approval !== null && $approvalRef === null) {
            $f->error('approval_reference', 'Enter the mail details (date, from, subject).');
        }
        $date = $f->date('dc_date', 'DC date');
        $lines = $f->lines(false, 'Value', true);
        $fy = $date ? $f->fy($date, 'dc_date') : null;
        $orderId = null;
        $orderNo = $f->text('order_no', 40);
        if ($orderNo !== null && $customer['id'] !== null) {
            $orderId = Database::value('SELECT id FROM pending_orders WHERE order_no = ? AND customer_id = ? AND deleted_at IS NULL', [$orderNo, $customer['id']]);
            if (!$orderId) {
                $f->error('order_no', "Order {$orderNo} was not found for this customer.");
            }
        }
        if ($f->failed()) {
            return null;
        }
        $no = NumberSequence::next('dc');
        Database::query(
            "INSERT INTO dc_records (financial_year_id, dc_no, dc_date, order_id, customer_id, branch_id, employee_id, supply_status, pending_status,
                                     approval_type, approval_reference, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'supplied', 'pending', ?, ?, ?, ?, ?)",
            [$fy['id'], $no, $date, $orderId ? (int) $orderId : null, $customer['id'], $customer['branch_id'], $customer['employee_id'],
             $approval, $approvalRef, $f->text('remarks', 500), $f->uid(), $f->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare('INSERT INTO dc_items (dc_id, product_id, quantity, dc_value) VALUES (?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['qty'], Money::toDecimal($l['amount'])]);
        }
        Audit::log('dc.created', 'dc', $id, null, ['dc_no' => $no, 'approval' => $approval, 'source' => 'request form']);
        return $id;
    }

    private static function saveSample(RequestForm $f): ?int
    {
        $customer = $f->customer(true);
        $kind = $f->choice('sample_type', ['returnable' => 'Returnable', 'non_returnable' => 'Non-returnable'], 'Sample type');
        $date = $f->date('document_date', 'Sample date');
        $lines = $f->lines(false, 'Value', true);
        $fy = $date ? $f->fy($date, 'document_date') : null;
        if ($f->failed()) {
            return null;
        }
        // A manager's own request is approved at once; anyone else's waits for a manager.
        $selfApprove = Gate::allows('samples.approve', $f->user);
        $no = NumberSequence::next('sample');
        Database::query(
            "INSERT INTO samples (financial_year_id, document_no, document_date, customer_id, branch_id, employee_id, supply_status, sample_type,
                                  approval_status, approved_by, approved_at, approval_note, pending_status, remarks, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, 'not_supplied', ?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
            [$fy['id'], $no, $date, $customer['id'], $customer['branch_id'], $customer['employee_id'], $kind,
             $selfApprove ? 'approved' : 'requested', $selfApprove ? $f->uid() : null, $selfApprove ? date('Y-m-d H:i:s') : null,
             $selfApprove ? 'Requested by a manager' : null, $f->text('remarks', 500), $f->uid(), $f->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        $ins = Database::connection()->prepare('INSERT INTO sample_items (sample_id, product_id, quantity, sample_value) VALUES (?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$id, $l['product_id'], $l['qty'], Money::toDecimal($l['amount'])]);
        }
        Audit::log('sample.requested', 'samples', $id, null, ['document_no' => $no, 'type' => $kind, 'approved' => $selfApprove]);
        return $id;
    }

    // =========================================================================
    // Manager approval of samples
    // =========================================================================

    public static function decideSample(array $p): void
    {
        $user = Auth::user();
        $s = self::scoped('samples', 's', (int) ($p['id'] ?? 0), $user);
        if ($s === null) {
            Response::error(404, 'Sample request not found.');
            return;
        }
        $decision = ($_POST['decision'] ?? '') === 'reject' ? 'rejected' : 'approved';
        if ($s['approval_status'] !== 'requested') {
            Session::flash('error', 'This sample request was already ' . $s['approval_status'] . '.');
        } else {
            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null;
            Database::query('UPDATE samples SET approval_status = ?, approved_by = ?, approved_at = NOW(), approval_note = ?, updated_by = ? WHERE id = ?',
                [$decision, $user['id'], $note, $user['id'], $s['id']]);
            Audit::log('sample.' . $decision, 'samples', (int) $s['id'], ['approval_status' => 'requested'], ['approval_status' => $decision, 'note' => $note]);
            Session::flash('success', "Sample {$s['document_no']} {$decision}.");
        }
        Response::redirect('/requests?type=sample');
    }

    // =========================================================================
    // Printable view (lead sheet / offer / order / DC / sample)
    // =========================================================================

    public static function show(array $p): void
    {
        $user = Auth::user();
        $type = (string) ($p['type'] ?? '');
        $id = (int) ($p['id'] ?? 0);
        $tables = ['lead' => 'leads', 'enquiry' => 'leads', 'order' => 'pending_orders', 'dc' => 'dc_records', 'sample' => 'samples'];
        if (!isset($tables[$type])) {
            Response::error(404, 'Not found.');
            return;
        }
        $doc = self::scoped($tables[$type], 'x', $id, $user);
        if ($doc === null || (in_array($type, ['lead', 'enquiry'], true) && $doc['record_type'] !== $type)) {
            Response::error(404, 'Not found.');
            return;
        }
        $lines = match ($type) {
            'lead', 'enquiry' => Database::fetchAll('SELECT i.quantity, i.unit_price AS price, i.amount, COALESCE(p.name, i.description) AS product, p.product_code, p.unit
                                                     FROM lead_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.lead_id = ? ORDER BY i.id', [$id]),
            'order'  => Database::fetchAll('SELECT i.order_qty AS quantity, i.rate AS price, i.order_value AS amount, p.name AS product, p.product_code, p.unit
                                            FROM pending_order_items i JOIN products p ON p.id = i.product_id WHERE i.order_id = ? ORDER BY i.id', [$id]),
            'dc'     => Database::fetchAll('SELECT i.quantity, NULL AS price, i.dc_value AS amount, p.name AS product, p.product_code, p.unit
                                            FROM dc_items i JOIN products p ON p.id = i.product_id WHERE i.dc_id = ? ORDER BY i.id', [$id]),
            'sample' => Database::fetchAll('SELECT i.quantity, NULL AS price, i.sample_value AS amount, p.name AS product, p.product_code, p.unit
                                            FROM sample_items i JOIN products p ON p.id = i.product_id WHERE i.sample_id = ? ORDER BY i.id', [$id]),
        };
        $customer = $doc['customer_id'] ? Database::fetch('SELECT customer_code, name, company_name, mobile, email, city FROM customers WHERE id = ?', [$doc['customer_id']]) : null;
        Response::view('requests/show', [
            'title'    => self::TYPES[$type][0] . ' · Requests',
            'flash'    => Session::takeFlash(),
            'type'     => $type,
            'doc'      => $doc,
            'lines'    => $lines,
            'customer' => $customer,
            'company'  => \App\Modules\Sms\SmsService::company(),
            'employee' => $doc['employee_id'] ? Database::value("SELECT CONCAT(COALESCE(short_name, ''), ' - ', name) FROM employees WHERE id = ?", [$doc['employee_id']]) : null,
            'informed' => !empty($doc['informed_employee_id']) ? Database::value('SELECT name FROM employees WHERE id = ?', [$doc['informed_employee_id']]) : null,
            'approver' => !empty($doc['approved_by']) ? Database::value('SELECT name FROM users WHERE id = ?', [$doc['approved_by']]) : null,
            'canApprove' => Gate::allows('samples.approve', $user),
            'canSms'   => Gate::allows('sms.send', $user),
        ]);
    }

    // =========================================================================

    /** One record in the user's data scope. @return array<string, mixed>|null */
    private static function scoped(string $table, string $a, int $id, array $user): ?array
    {
        [$w, $p] = DataScope::for($user)->where("{$a}.branch_id", "{$a}.employee_id");
        return Database::fetch("SELECT {$a}.* FROM {$table} {$a} WHERE {$a}.id = ? AND {$a}.deleted_at IS NULL AND {$w}", array_merge([$id], $p));
    }

    /** @return list<array<string, mixed>> latest requests of this type in scope */
    private static function recent(string $type, array $user): array
    {
        $scope = DataScope::for($user);
        [$w, $p] = $scope->where('x.branch_id', 'x.employee_id');
        return match ($type) {
            'lead', 'enquiry' => Database::fetchAll(
                "SELECT x.id, x.lead_number AS number, x.created_at AS date, x.name AS customer, x.lead_type, x.status, x.expected_value AS value, e.short_name AS employee
                 FROM leads x LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.deleted_at IS NULL AND x.record_type = ? AND {$w} ORDER BY x.id DESC LIMIT 25", array_merge([$type], $p)),
            'order' => Database::fetchAll(
                "SELECT x.id, x.order_no AS number, x.order_date AS date, c.name AS customer, x.reference_type AS ref, x.status,
                        (SELECT SUM(order_value) FROM pending_order_items WHERE order_id = x.id) AS value, e.short_name AS employee
                 FROM pending_orders x JOIN customers c ON c.id = x.customer_id LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.deleted_at IS NULL AND {$w} ORDER BY x.id DESC LIMIT 25", $p),
            'dc' => Database::fetchAll(
                "SELECT x.id, x.dc_no AS number, x.dc_date AS date, c.name AS customer, x.approval_type AS ref, x.pending_status AS status,
                        (SELECT SUM(dc_value) FROM dc_items WHERE dc_id = x.id) AS value, e.short_name AS employee
                 FROM dc_records x JOIN customers c ON c.id = x.customer_id LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.deleted_at IS NULL AND {$w} ORDER BY x.id DESC LIMIT 25", $p),
            'sample' => Database::fetchAll(
                "SELECT x.id, x.document_no AS number, x.document_date AS date, c.name AS customer, x.sample_type AS ref, x.approval_status AS status,
                        (SELECT SUM(sample_value) FROM sample_items WHERE sample_id = x.id) AS value, e.short_name AS employee
                 FROM samples x JOIN customers c ON c.id = x.customer_id LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.deleted_at IS NULL AND {$w} ORDER BY x.approval_status = 'requested' DESC, x.id DESC LIMIT 25", $p),
        };
    }
}
