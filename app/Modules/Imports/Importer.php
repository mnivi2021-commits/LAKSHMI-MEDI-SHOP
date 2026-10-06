<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\DataScope;
use App\Core\Database;
use App\Core\Money;
use DateTimeImmutable;

/**
 * Base class for one kind of spreadsheet import.
 *
 * A subclass declares its columns (fields()), validates one row at a time
 * (validateRow), says whether a record already exists (existing), and writes
 * one record (store). For "document" imports - an order / invoice / sample / DC
 * with several product lines - rows sharing the same document number are grouped
 * (groupField) and written together as one document.
 *
 * Validation never writes anything; store() is only called inside the run
 * transaction, after the whole batch has been re-validated.
 */
abstract class Importer
{
    protected const DOC_NO = '/^[A-Za-z0-9][A-Za-z0-9\/\-_. ]{0,39}$/';

    /** @var list<string> errors for the row being validated */
    protected array $errors = [];

    /** @var array<string, mixed> small per-batch lookup caches */
    private array $cache = [];

    public function __construct(protected readonly array $user, protected readonly DataScope $scope, protected readonly DateTimeImmutable $today) {}

    abstract public function key(): string;
    abstract public function label(): string;
    abstract public function permission(): string;

    /**
     * Columns, in template order.
     * @return array<string, array{label: string, required?: bool, format?: string, aliases?: list<string>, example?: list<string>}>
     */
    abstract public function fields(): array;

    /**
     * Validate one mapped row (field => trimmed string).
     * @return array{0: array<string, mixed>|null, 1: list<string>} [clean data, errors]
     */
    abstract public function validateRow(array $in): array;

    /** If this record already exists in the CRM, a message saying so (row is then skipped as a duplicate). */
    abstract public function existing(array $data): ?string;

    /**
     * Write one record (one row, or all lines of one document).
     * @param list<array<string, mixed>> $lines
     * @return array{0: string, 1: int} [table, id]
     */
    abstract public function store(array $lines, int $batchId): array;

    /** Field whose value groups rows into one document (null = every row is its own record). */
    public function groupField(): ?string
    {
        return null;
    }

    /** Which document a valid line belongs to (same FY + same number). */
    public function documentKey(array $data): ?string
    {
        $g = $this->groupField();
        return $g === null ? null : ($data['fy_id'] ?? '') . '|' . strtoupper((string) $data[$g]);
    }

    /** Key identifying the record inside one file, to catch the same record twice. */
    public function recordKey(array $data): ?string
    {
        return null;
    }

    /** Fields that must be identical on every line of a document. @return list<string> */
    public function headerFields(): array
    {
        return [];
    }

    /** Extra checks across all lines of one document. @param list<array<string, mixed>> $lines @return list<string> */
    public function validateDocument(array $lines): array
    {
        return [];
    }

    /** Lines shown under the template's instructions. @return list<string> */
    public function notes(): array
    {
        return [];
    }

    // =========================================================================
    // Field helpers: each adds a message to $this->errors and returns null on failure
    // =========================================================================

    protected function begin(): void
    {
        $this->errors = [];
    }

    /** @return array{0: array<string, mixed>|null, 1: list<string>} */
    protected function done(array $data): array
    {
        return $this->errors ? [null, $this->errors] : [$data, []];
    }

    protected function label_(string $field): string
    {
        return $this->fields()[$field]['label'] ?? $field;
    }

    protected function text(array $in, string $field, int $max, bool $required = false): ?string
    {
        $v = (string) ($in[$field] ?? '');
        if ($v === '') {
            if ($required) {
                $this->errors[] = $this->label_($field) . ' is required.';
            }
            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->errors[] = $this->label_($field) . " is longer than {$max} characters.";
            return null;
        }
        return $v;
    }

    protected function docNo(array $in, string $field): ?string
    {
        $v = (string) ($in[$field] ?? '');
        if ($v === '') {
            $this->errors[] = $this->label_($field) . ' is required.';
            return null;
        }
        if (!preg_match(self::DOC_NO, $v)) {
            $this->errors[] = $this->label_($field) . ' may use up to 40 letters, digits, / - _ . or spaces.';
            return null;
        }
        return $v;
    }

    /** Accepts DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY, YYYY-MM-DD, 05-Apr-2026, 2-digit years and Excel serial numbers. */
    protected function date(array $in, string $field, bool $required, bool $notFuture = true): ?string
    {
        $v = (string) ($in[$field] ?? '');
        if ($v === '') {
            if ($required) {
                $this->errors[] = $this->label_($field) . ' is required.';
            }
            return null;
        }
        $d = self::parseDate($v);
        if ($d === null) {
            $this->errors[] = $this->label_($field) . " \"{$v}\" is not a valid date (use DD-MM-YYYY).";
            return null;
        }
        if ($notFuture && $d > $this->today->format('Y-m-d')) {
            $this->errors[] = $this->label_($field) . ' cannot be in the future.';
            return null;
        }
        return $d;
    }

    public static function parseDate(string $v): ?string
    {
        $v = trim(preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', trim($v)));   // drop a time part
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 20000 && (float) $v < 80000) {
            return gmdate('Y-m-d', ((int) $v - 25569) * 86400);                     // Excel serial in a CSV
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})$/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\s\/.]([A-Za-z]{3,9})[-\s\/.,]+(\d{2}|\d{4})$/', $v, $m) && isset($months[substr(strtolower($m[2]), 0, 3)])) {
            [$d, $mo, $y] = [(int) $m[1], $months[substr(strtolower($m[2]), 0, 3)], (int) $m[3]];
        } else {
            return null;
        }
        if ($y < 100) {
            $y += 2000;
        }
        if ($y < 2000 || $y > 2099 || !checkdate($mo, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** @return array<string, mixed>|null financial year containing the date (not locked) */
    protected function fy(?string $date, string $field): ?array
    {
        if ($date === null) {
            return null;
        }
        $fy = $this->cached("fy:{$date}", static fn () => Database::fetch('SELECT id, label, is_locked FROM financial_years WHERE ? BETWEEN start_date AND end_date', [$date]));
        if ($fy === null) {
            $this->errors[] = $this->label_($field) . ': no financial year is set up for this date.';
            return null;
        }
        if ((int) $fy['is_locked'] === 1) {
            $this->errors[] = "{$fy['label']} is locked; entries are not allowed.";
            return null;
        }
        return $fy;
    }

    /** Paise. Accepts 1,25,000.50 / ₹ 500 / Rs. 500. */
    protected function money(array $in, string $field, bool $required, bool $allowZero = false): ?int
    {
        $v = (string) ($in[$field] ?? '');
        if ($v === '') {
            if ($required) {
                $this->errors[] = $this->label_($field) . ' is required.';
            }
            return null;
        }
        $p = Money::parse(preg_replace('/^(rs\.?|inr)\s*/i', '', $v));
        if ($p === null) {
            $this->errors[] = $this->label_($field) . " \"{$v}\" is not a valid amount (e.g. 12500 or 12,500.50).";
            return null;
        }
        if ($p === 0 && !$allowZero) {
            $this->errors[] = $this->label_($field) . ' must be more than zero.';
            return null;
        }
        return $p;
    }

    /** Decimal string with up to 3 places. */
    protected function qty(array $in, string $field, bool $required, bool $allowZero = false): ?string
    {
        $v = str_replace(',', '', (string) ($in[$field] ?? ''));
        if ($v === '') {
            if ($required) {
                $this->errors[] = $this->label_($field) . ' is required.';
            }
            return null;
        }
        if (!preg_match('/^\d{1,11}(\.\d{1,3})?$/', $v) || (!$allowZero && (float) $v <= 0)) {
            $this->errors[] = $this->label_($field) . " \"{$v}\" must be a number" . ($allowZero ? '' : ' greater than zero') . ' (up to 3 decimals).';
            return null;
        }
        return $v;
    }

    /** Indian mobile, stored as the last 10 digits. */
    protected function mobile(array $in, string $field): ?string
    {
        $v = preg_replace('/[\s-]/', '', (string) ($in[$field] ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^(\+91|91|0)?([6-9]\d{9})$/', $v, $m)) {
            $this->errors[] = $this->label_($field) . " \"{$v}\" must be a valid 10-digit mobile number.";
            return null;
        }
        return $m[2];
    }

    protected function email(array $in, string $field): ?string
    {
        $v = strtolower((string) ($in[$field] ?? ''));
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > 150 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = $this->label_($field) . " \"{$v}\" is not a valid email address.";
            return null;
        }
        return $v;
    }

    /** Match a value against allowed options (case/space-insensitive, with synonyms). @param array<string, string> $map normalised text => stored value */
    protected function choice(array $in, string $field, array $map, ?string $default): ?string
    {
        $v = (string) ($in[$field] ?? '');
        if ($v === '') {
            return $default;
        }
        $k = preg_replace('/[^a-z0-9]/', '', strtolower($v));
        if (isset($map[$k])) {
            return $map[$k];
        }
        $this->errors[] = $this->label_($field) . " \"{$v}\" is not one of: " . implode(', ', array_unique(array_values($map))) . '.';
        return null;
    }

    /** @return array<string, mixed>|null customer by code, active or inactive (not blocked), in the user's scope */
    protected function customer(array $in, string $field = 'customer_code'): ?array
    {
        $code = (string) ($in[$field] ?? '');
        if ($code === '') {
            $this->errors[] = $this->label_($field) . ' is required.';
            return null;
        }
        $c = $this->cached('cus:' . strtoupper($code), static fn () => Database::fetch(
            'SELECT id, customer_code, name, branch_id, employee_id, credit_days, status FROM customers WHERE customer_code = ? AND deleted_at IS NULL', [$code]
        ));
        if ($c === null) {
            $this->errors[] = "Customer code \"{$code}\" was not found. Add the customer first (or import customers).";
            return null;
        }
        if ($c['status'] === 'blocked') {
            $this->errors[] = "Customer {$code} is blocked.";
            return null;
        }
        if (!$this->scope->allowsBranch((int) $c['branch_id']) || !$this->scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
            $this->errors[] = "You do not have access to customer {$code}.";
            return null;
        }
        return $c;
    }

    /** @return array<string, mixed>|null */
    protected function product(array $in, string $field, bool $required): ?array
    {
        $code = (string) ($in[$field] ?? '');
        if ($code === '') {
            if ($required) {
                $this->errors[] = $this->label_($field) . ' is required.';
            }
            return null;
        }
        $p = $this->cached('prd:' . strtoupper($code), static fn () => Database::fetch(
            "SELECT id, product_code, name, gst_rate, rate FROM products WHERE product_code = ? AND deleted_at IS NULL", [$code]
        ));
        if ($p === null) {
            $this->errors[] = "Product code \"{$code}\" was not found.";
        }
        return $p;
    }

    /** @return array<string, mixed>|null */
    protected function branch(array $in, string $field, bool $required): ?array
    {
        $code = strtoupper((string) ($in[$field] ?? ''));
        if ($code === '') {
            if ($required) {
                $this->errors[] = $this->label_($field) . ' is required.';
            }
            return null;
        }
        $b = $this->cached("br:{$code}", static fn () => Database::fetch('SELECT id, branch_code, name FROM branches WHERE branch_code = ? AND deleted_at IS NULL', [$code]));
        if ($b === null) {
            $this->errors[] = "Branch code \"{$code}\" was not found.";
            return null;
        }
        if (!$this->scope->allowsBranch((int) $b['id'])) {
            $this->errors[] = "You do not have access to branch {$code}.";
            return null;
        }
        return $b;
    }

    /**
     * Sales employee by employee code or short name (e.g. EMP002 or JANA); when the
     * column is empty, the customer's assigned employee is used.
     */
    protected function employee(array $in, string $field, ?array $customer): ?int
    {
        $v = strtoupper((string) ($in[$field] ?? ''));
        if ($v === '') {
            $id = $customer['employee_id'] ?? null;
            if ($id === null && $customer !== null && $this->scope->employeeIds !== null) {
                $this->errors[] = "Customer {$customer['customer_code']} has no sales employee; fill the " . $this->label_($field) . ' column.';
            }
            return $id !== null ? (int) $id : null;
        }
        $e = $this->cached("emp:{$v}", static fn () => Database::fetch(
            "SELECT id, branch_id FROM employees WHERE (employee_code = ? OR short_name = ?) AND deleted_at IS NULL AND status = 'active'
             ORDER BY employee_code = ? DESC LIMIT 1", [$v, $v, $v]
        ));
        if ($e === null) {
            $this->errors[] = "Employee \"{$v}\" was not found (use the employee code or short name).";
            return null;
        }
        if (!$this->scope->allowsBranch((int) $e['branch_id']) || !$this->scope->allowsEmployee((int) $e['id'])) {
            $this->errors[] = "You do not have access to employee {$v}.";
            return null;
        }
        return (int) $e['id'];
    }

    protected function cached(string $key, callable $load): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $load();
        }
        return $this->cache[$key];
    }

    protected function uid(): int
    {
        return (int) $this->user['id'];
    }

    /** Value for a nullable DECIMAL column. */
    protected static function dec(?int $paise): ?string
    {
        return $paise === null ? null : Money::toDecimal($paise);
    }
}
