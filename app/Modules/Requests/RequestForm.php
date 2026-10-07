<?php

declare(strict_types=1);

namespace App\Modules\Requests;

use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NumberSequence;
use App\Modules\Imports\Importer;
use DateTimeImmutable;

/**
 * Reads and checks the shared parts of the request forms. Problems are collected
 * in $errors (field => message); callers check failed() before writing.
 */
final class RequestForm
{
    private DataScope $scope;

    /** @param array<string, mixed> $in @param array<string, string> $errors */
    public function __construct(public readonly array $user, private readonly array $in, private array &$errors)
    {
        $this->scope = DataScope::for($user);
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    public function error(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function uid(): int
    {
        return (int) $this->user['id'];
    }

    public function text(string $field, int $max): ?string
    {
        $v = trim((string) ($this->in[$field] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** @param array<string, string> $options */
    public function choice(string $field, array $options, string $label): ?string
    {
        $v = (string) ($this->in[$field] ?? '');
        if (!array_key_exists($v, $options)) {
            $this->error($field, "Choose: {$label}.");
            return null;
        }
        return $v;
    }

    public function date(string $field, string $label, bool $required = true, bool $notFuture = true): ?string
    {
        $raw = trim((string) ($this->in[$field] ?? ''));
        if ($raw === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        $d = Importer::parseDate($raw);
        if ($d === null) {
            $this->error($field, "{$label} is not a valid date.");
            return null;
        }
        if ($notFuture && $d > date('Y-m-d')) {
            $this->error($field, "{$label} cannot be in the future.");
            return null;
        }
        return $d;
    }

    public function money(string $field, string $label, bool $required): ?int
    {
        $raw = trim((string) ($this->in[$field] ?? ''));
        if ($raw === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        $p = Money::parse($raw);
        if ($p === null || ($required && $p === 0)) {
            $this->error($field, "{$label} must be an amount like 12500.");
            return null;
        }
        return $p;
    }

    /** @return array<string, mixed>|null open financial year for the date */
    public function fy(string $date, string $field): ?array
    {
        $fy = Database::fetch('SELECT id, label, is_locked FROM financial_years WHERE ? BETWEEN start_date AND end_date', [$date]);
        if ($fy === null || (int) $fy['is_locked'] === 1) {
            $this->error($field, $fy === null ? 'No financial year covers this date.' : "{$fy['label']} is locked.");
            return null;
        }
        return $fy;
    }

    /** Who passed this on: Manager or Rep, and which employee. @return array{by: ?string, employee_id: ?int} */
    public function informed(): array
    {
        $by = $this->choice('informed_by', ['manager' => 'Manager', 'rep' => 'Rep'], 'Informed by (Manager / Rep)');
        $emp = (int) ($this->in['informed_employee_id'] ?? 0) ?: null;
        if ($emp !== null && !Database::value("SELECT 1 FROM employees WHERE id = ? AND deleted_at IS NULL AND status = 'active'", [$emp])) {
            $this->error('informed_employee_id', 'Choose who informed.');
            $emp = null;
        }
        return ['by' => $by, 'employee_id' => $emp];
    }

    /**
     * The customer: picked from the master, or typed as new. For orders, DC and samples a new
     * customer is added to the customer master at once (needs customers.add).
     *
     * @return array{id: ?int, name: ?string, company: ?string, mobile: ?string, email: ?string, branch_id: ?int, employee_id: ?int}
     */
    public function customer(bool $mustBeInMaster): array
    {
        $out = ['id' => null, 'name' => null, 'company' => null, 'mobile' => null, 'email' => null, 'branch_id' => null, 'employee_id' => null];
        $id = (int) ($this->in['customer_id'] ?? 0) ?: null;
        if ($id !== null) {
            $c = Database::fetch("SELECT id, name, company_name, mobile, email, branch_id, employee_id, status FROM customers WHERE id = ? AND deleted_at IS NULL", [$id]);
            if ($c === null || $c['status'] === 'blocked' || !$this->scope->allowsBranch((int) $c['branch_id'])
                || !$this->scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
                $this->error('customer_id', 'Choose one of your customers.');
                return $out;
            }
            return ['id' => (int) $c['id'], 'name' => $c['name'], 'company' => $c['company_name'], 'mobile' => $c['mobile'], 'email' => $c['email'],
                    'branch_id' => (int) $c['branch_id'], 'employee_id' => $c['employee_id'] !== null ? (int) $c['employee_id'] : $this->ownEmployee()];
        }

        // New customer typed in
        $name = $this->text('new_name', 150);
        if ($name === null) {
            $this->error('customer_id', 'Choose the customer, or fill in "New customer".');
            return $out;
        }
        $mobile = $this->text('new_mobile', 20);
        $email = $this->text('new_email', 150);
        if ($mobile !== null) {
            $digits = preg_replace('/[\s-]/', '', $mobile);
            if (!preg_match('/^(\+91|91|0)?([6-9]\d{9})$/', $digits, $m)) {
                $this->error('new_mobile', 'Enter a valid 10-digit mobile number.');
            } else {
                $mobile = $m[2];
            }
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('new_email', 'Enter a valid email address.');
        }
        if ($mobile === null && $email === null) {
            $this->error('new_mobile', 'Give a mobile number or an email for the new customer.');
        }
        $branch = (int) ($this->in['branch_id'] ?? 0) ?: null;
        if ($branch === null || !$this->scope->allowsBranch($branch) || !Database::value('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL', [$branch])) {
            $this->error('branch_id', 'Choose the branch for the new customer.');
            $branch = null;
        }
        $emp = (int) ($this->in['employee_id'] ?? 0) ?: $this->ownEmployee();
        if ($emp !== null && !$this->scope->allowsEmployee($emp)) {
            $this->error('employee_id', 'You do not have access to this employee.');
        }
        $out = ['id' => null, 'name' => $name, 'company' => $this->text('new_company', 150), 'mobile' => $mobile, 'email' => $email,
                'branch_id' => $branch, 'employee_id' => $emp];
        if (!$mustBeInMaster || $this->failed()) {
            return $out;
        }

        // Orders, DC and samples belong to a customer in the master: add it now.
        if (!Gate::allows('customers.add', $this->user)) {
            $this->error('customer_id', 'This customer is not in the customer master. Ask someone who can add customers to add it first.');
            return $out;
        }
        if ($mobile !== null && ($dup = Database::fetch('SELECT customer_code, name FROM customers WHERE mobile = ? AND deleted_at IS NULL', [$mobile]))) {
            $this->error('new_mobile', "This mobile already belongs to {$dup['name']} ({$dup['customer_code']}); choose that customer instead.");
            return $out;
        }
        $code = NumberSequence::next('customer');
        Database::query(
            'INSERT INTO customers (customer_code, name, company_name, mobile, email, city, branch_id, employee_id, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$code, $name, $out['company'], $mobile, $email, $this->text('new_city', 80), $branch, $emp, $this->uid(), $this->uid()]
        );
        $out['id'] = (int) Database::connection()->lastInsertId();
        \App\Core\Audit::log('customer.created', 'customers', $out['id'], null, ['customer_code' => $code, 'name' => $name, 'via' => 'request form']);
        return $out;
    }

    /**
     * Product lines. Blank lines are skipped. A product from the master is required unless
     * $freeText (leads / enquiries may name a product that is not in the master yet).
     *
     * @return list<array{product_id: ?int, description: ?string, qty: string, price: int, amount: int}>
     */
    public function lines(bool $freeText, string $priceLabel, bool $valueIsTotal = false): array
    {
        $rows = is_array($this->in['lines'] ?? null) ? $this->in['lines'] : [];
        $out = [];
        foreach (array_slice($rows, 0, RequestController::LINES, true) as $i => $r) {
            $r = is_array($r) ? $r : [];
            $pid = (int) ($r['product_id'] ?? 0) ?: null;
            $desc = trim((string) ($r['description'] ?? '')) ?: null;
            $qtyRaw = str_replace(',', '', trim((string) ($r['qty'] ?? '')));
            $priceRaw = trim((string) ($r['price'] ?? ''));
            if ($pid === null && $desc === null && $qtyRaw === '' && $priceRaw === '') {
                continue;
            }
            $key = "lines.{$i}";
            if ($pid !== null && !Database::value('SELECT 1 FROM products WHERE id = ? AND deleted_at IS NULL', [$pid])) {
                $this->error("{$key}.product", 'Choose a product from the list.');
            }
            if ($pid === null && (!$freeText || $desc === null)) {
                $this->error("{$key}.product", $freeText ? 'Choose a product or type what the customer needs.' : 'Choose a product from the list.');
            }
            if (!preg_match('/^\d{1,11}(\.\d{1,3})?$/', $qtyRaw) || (float) $qtyRaw <= 0) {
                $this->error("{$key}.qty", 'Quantity must be more than zero.');
                continue;
            }
            $price = $priceRaw === '' ? 0 : Money::parse($priceRaw);
            if ($price === null) {
                $this->error("{$key}.price", "{$priceLabel} must be an amount.");
                continue;
            }
            $amount = $valueIsTotal ? $price : Money::times($price, $qtyRaw);
            $out[] = ['product_id' => $pid, 'description' => $pid === null ? mb_substr((string) $desc, 0, 200) : null, 'qty' => $qtyRaw, 'price' => $price, 'amount' => $amount];
        }
        if ($out === [] && !$this->failed()) {
            $this->error('lines.0.product', 'Add at least one product line.');
        }
        return $out;
    }

    private function ownEmployee(): ?int
    {
        return !$this->scope->isUnrestricted() && !empty($this->user['employee_id']) ? (int) $this->user['employee_id'] : null;
    }
}
