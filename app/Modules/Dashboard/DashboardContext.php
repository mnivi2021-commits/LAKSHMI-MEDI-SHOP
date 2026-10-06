<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Database;
use App\Core\DataScope;
use App\Core\DateRange;
use App\Core\FinancialYear;
use DateTimeImmutable;

/**
 * Resolves dashboard filters into one trusted context:
 *   - every filter is validated against the user's data scope (never trusted from the URL)
 *   - the "as on" date that all KPI windows are measured from
 *   - an SQL condition builder combining data scope + filters
 *
 * "As on" rules
 *   current FY, no month / current month  -> today
 *   any FY, past month selected           -> last day of that month
 *   past FY, no month                     -> last day of that FY
 * Future FYs and future months are not selectable.
 */
final class DashboardContext
{
    /** @var array{fy_id: int, month: ?string, branch_id: ?int, employee_id: ?int, customer_id: ?int, product_id: ?int} */
    public array $filters;

    public FinancialYear $fy;
    public DateTimeImmutable $asOn;
    public bool $isLive;               // as-on is today (figures still moving)

    /** @var array<string, mixed> */
    public array $fyRow;
    /** @var list<string> human-readable notes about ignored filters */
    public array $notices = [];
    public ?string $customerLabel = null;
    public ?string $productLabel = null;

    private function __construct(public readonly array $user, public readonly DataScope $scope) {}

    /** @param array<string, mixed> $query typically $_GET */
    public static function fromRequest(array $user, array $query, ?DateTimeImmutable $today = null): self
    {
        $ctx = new self($user, DataScope::for($user));
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);

        // --- Financial year ---------------------------------------------------
        $fyRow = null;
        $fyId = self::int($query['fy'] ?? null);
        if ($fyId !== null) {
            $fyRow = Database::fetch('SELECT * FROM financial_years WHERE id = ? AND start_date <= ?', [$fyId, $today->format('Y-m-d')]);
            if ($fyRow === null) {
                $ctx->notices[] = 'The selected financial year is not available; showing the current year.';
            }
        }
        $fyRow ??= Database::fetch('SELECT * FROM financial_years WHERE ? BETWEEN start_date AND end_date', [$today->format('Y-m-d')])
            ?? Database::fetch('SELECT * FROM financial_years WHERE start_date <= ? ORDER BY start_date DESC LIMIT 1', [$today->format('Y-m-d')]);
        if ($fyRow === null) {
            throw new \RuntimeException('No financial year is configured. Add one in financial_years.');
        }
        $ctx->fyRow = $fyRow;
        $fyStart = new DateTimeImmutable($fyRow['start_date']);
        $fyEnd = new DateTimeImmutable($fyRow['end_date']);

        // --- Month (YYYY-MM inside the FY, not in the future) ------------------------
        $month = null;
        if (is_string($query['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $query['month'])) {
            $first = new DateTimeImmutable($query['month'] . '-01');
            if ($first >= $fyStart && $first <= $fyEnd && $first <= $today) {
                $month = $query['month'];
            } else {
                $ctx->notices[] = 'The selected month is outside this financial year or in the future; showing the whole year.';
            }
        }

        // --- As-on date -------------------------------------------------------------
        if ($month !== null) {
            $monthEnd = (new DateTimeImmutable($month . '-01'))->modify('last day of this month');
            $asOn = $monthEnd < $today ? $monthEnd : $today;
        } else {
            $asOn = $fyEnd < $today ? $fyEnd : $today;
        }
        $ctx->asOn = $asOn;
        $ctx->isLive = $asOn == $today;
        $ctx->fy = new FinancialYear($asOn, (int) $fyStart->format('n'));

        // --- Branch / employee / customer / product (each checked against scope) ---
        $branchId = self::int($query['branch'] ?? null);
        if ($branchId !== null && !array_key_exists($branchId, $ctx->branchOptions())) {
            $ctx->notices[] = 'You do not have access to the selected branch.';
            $branchId = null;
        }

        $employeeId = self::int($query['employee'] ?? null);
        if ($employeeId !== null && !array_key_exists($employeeId, $ctx->employeeOptions(null))) {
            $ctx->notices[] = 'You do not have access to the selected employee.';
            $employeeId = null;
        }

        $customerId = self::int($query['customer'] ?? null);
        if ($customerId !== null) {
            $c = Database::fetch('SELECT id, customer_code, name, branch_id, employee_id FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
            if ($c === null || !$ctx->scope->allowsBranch((int) $c['branch_id']) || !$ctx->scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
                $ctx->notices[] = 'You do not have access to the selected customer.';
                $customerId = null;
            } else {
                $ctx->customerLabel = $c['name'] . ' (' . $c['customer_code'] . ')';
            }
        }

        $productId = self::int($query['product'] ?? null);
        if ($productId !== null) {
            $p = Database::fetch('SELECT id, product_code, name FROM products WHERE id = ? AND deleted_at IS NULL', [$productId]);
            if ($p === null) {
                $productId = null;
            } else {
                $ctx->productLabel = $p['name'] . ' (' . $p['product_code'] . ')';
            }
        }

        $ctx->filters = [
            'fy_id'       => (int) $fyRow['id'],
            'month'       => $month,
            'branch_id'   => $branchId,
            'employee_id' => $employeeId,
            'customer_id' => $customerId,
            'product_id'  => $productId,
        ];
        return $ctx;
    }

    // -------------------------------------------------------------------------
    // Date windows (all relative to as-on; never overlapping)
    // -------------------------------------------------------------------------

    /** @return array<string, DateRange|null> */
    public function windows(): array
    {
        return [
            'fy_to_previous_day'    => $this->fy->fyToPreviousDay(),
            'month_to_previous_day' => $this->fy->monthToPreviousDay(),
            'today'                 => $this->fy->today(),
            'fy_to_date'            => $this->fy->fyToDate(),
            'month_to_date'         => $this->fy->monthToDate(),
            'full_year'             => $this->fy->fullYear(),
        ];
    }

    // -------------------------------------------------------------------------
    // SQL condition: data scope AND filters
    // -------------------------------------------------------------------------

    /**
     * @param array{branch: string, employee?: string, customer?: string, product?: string} $columns
     *        Column names on the queried row ('branch' is required: every business row has one).
     *        Omit 'product' for document-level rows: a product filter then matches nothing,
     *        so product-filtered KPIs must query a line-level view (v_sales_lines etc.).
     * @return array{0: string, 1: list<int>}
     */
    public function where(array $columns): array
    {
        if (!isset($columns['branch'])) {
            throw new \InvalidArgumentException('A branch column is required');
        }
        [$sql, $params] = $this->scope->where($columns['branch'], $columns['employee'] ?? null);
        $parts = [$sql];

        foreach (['branch' => 'branch_id', 'employee' => 'employee_id', 'customer' => 'customer_id', 'product' => 'product_id'] as $col => $key) {
            $value = $this->filters[$key];
            if ($value === null) {
                continue;
            }
            if (!isset($columns[$col])) {
                return ['1 = 0', []];      // filter cannot be applied to this row type
            }
            self::assertColumn($columns[$col]);
            $parts[] = $columns[$col] . ' = ?';
            $params[] = $value;
        }
        return [implode(' AND ', $parts), $params];
    }

    // -------------------------------------------------------------------------
    // Filter options (already limited to the user's scope)
    // -------------------------------------------------------------------------

    /** @return array<int, string> id => label */
    public function branchOptions(): array
    {
        $params = [];
        $sql = "SELECT id, CONCAT(name, ' (', branch_code, ')') AS label FROM branches WHERE deleted_at IS NULL AND status = 'active'";
        if ($this->scope->branchIds !== null) {
            if ($this->scope->branchIds === []) {
                return [];
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($this->scope->branchIds), '?')) . ')';
            $params = $this->scope->branchIds;
        }
        if ($this->scope->employeeIds !== null) {
            if ($this->scope->employeeIds === []) {
                return [];
            }
            $sql .= ' AND id IN (SELECT branch_id FROM employees WHERE id IN (' . implode(',', array_fill(0, count($this->scope->employeeIds), '?')) . '))';
            $params = array_merge($params, $this->scope->employeeIds);
        }
        return array_column(Database::fetchAll($sql . ' ORDER BY name', $params), 'label', 'id');
    }

    /** Sales representatives the user may see, optionally within one branch. @return array<int, string> */
    public function employeeOptions(?int $branchId): array
    {
        $sql = "SELECT id, CONCAT(COALESCE(short_name, name), ' - ', name) AS label FROM employees
                WHERE deleted_at IS NULL AND status = 'active' AND is_sales_rep = 1";
        $params = [];
        [$scopeSql, $scopeParams] = $this->scope->where('branch_id', 'id');
        $sql .= " AND {$scopeSql}";
        $params = array_merge($params, $scopeParams);
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        return array_column(Database::fetchAll($sql . ' ORDER BY COALESCE(short_name, name)', $params), 'label', 'id');
    }

    /** Months of the FY up to as-on/today, newest first. @return array<string, string> 'YYYY-MM' => 'Oct 2026' */
    public function monthOptions(DateTimeImmutable $today): array
    {
        $out = [];
        $d = new DateTimeImmutable($this->fyRow['start_date']);
        $end = new DateTimeImmutable($this->fyRow['end_date']);
        while ($d <= $end && $d <= $today) {
            $out[$d->format('Y-m')] = $d->format('M Y');
            $d = $d->modify('+1 month');
        }
        return array_reverse($out, true);
    }

    /** @return array<int, string> */
    public static function financialYearOptions(DateTimeImmutable $today): array
    {
        return array_column(
            Database::fetchAll('SELECT id, label FROM financial_years WHERE start_date <= ? ORDER BY start_date DESC', [$today->format('Y-m-d')]),
            'label', 'id'
        );
    }

    /** Query string for links that keep the current filters. */
    public function query(array $override = []): string
    {
        $q = array_filter([
            'fy'       => $this->filters['fy_id'],
            'month'    => $this->filters['month'],
            'branch'   => $this->filters['branch_id'],
            'employee' => $this->filters['employee_id'],
            'customer' => $this->filters['customer_id'],
            'product'  => $this->filters['product_id'],
        ], static fn ($v) => $v !== null);
        return http_build_query(array_filter(array_merge($q, $override), static fn ($v) => $v !== null));
    }

    private static function int(mixed $v): ?int
    {
        if (is_string($v) && ctype_digit($v) && (int) $v > 0) {
            return (int) $v;
        }
        return is_int($v) && $v > 0 ? $v : null;
    }

    private static function assertColumn(string $col): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $col)) {
            throw new \InvalidArgumentException('Invalid column name');
        }
    }
}
