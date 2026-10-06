<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Database;
use App\Core\DataScope;
use App\Modules\Imports\Importer;
use DateTimeImmutable;

/**
 * Validated report filters. Every value is checked against the user's data scope;
 * anything not allowed is dropped with a notice (never trusted from the URL).
 */
final class ReportFilters
{
    public string $from;
    public string $to;
    public string $asOn;
    public ?int $branchId = null;
    public ?int $employeeId = null;
    public ?int $customerId = null;
    public ?string $customerCode = null;
    /** @var list<string> */
    public array $notices = [];
    public readonly DataScope $scope;

    public function __construct(public readonly array $user, array $q, DateTimeImmutable $today)
    {
        $this->scope = DataScope::for($user);
        $t = $today->format('Y-m-d');
        $fy = Database::fetch('SELECT start_date FROM financial_years WHERE ? BETWEEN start_date AND end_date', [$t]);
        $from = Importer::parseDate((string) ($q['from'] ?? ''));
        $to = Importer::parseDate((string) ($q['to'] ?? ''));
        $this->from = $from ?? ($fy['start_date'] ?? $today->format('Y-01-01'));
        $this->to = $to ?? $t;
        if ($this->to < $this->from) {
            [$this->from, $this->to] = [$this->to, $this->from];
            $this->notices[] = 'The dates were the wrong way round and have been swapped.';
        }
        $asOn = Importer::parseDate((string) ($q['as_on'] ?? ''));
        $this->asOn = $asOn !== null && $asOn <= $t ? $asOn : $t;

        $b = (int) ($q['branch'] ?? 0);
        if ($b > 0) {
            $this->scope->allowsBranch($b) ? $this->branchId = $b : $this->notices[] = 'You do not have access to the selected branch.';
        }
        $e = (int) ($q['employee'] ?? 0);
        if ($e > 0) {
            $this->scope->allowsEmployee($e) ? $this->employeeId = $e : $this->notices[] = 'You do not have access to the selected employee.';
        }
        $code = strtoupper(trim((string) ($q['customer'] ?? '')));
        if ($code !== '') {
            $c = Database::fetch('SELECT id, branch_id, employee_id FROM customers WHERE customer_code = ? AND deleted_at IS NULL', [mb_substr($code, 0, 30)]);
            if ($c !== null && $this->scope->allowsBranch((int) $c['branch_id']) && $this->scope->allowsEmployee($c['employee_id'] !== null ? (int) $c['employee_id'] : null)) {
                $this->customerId = (int) $c['id'];
                $this->customerCode = $code;
            } else {
                $this->notices[] = "Customer {$code} was not found in your customers.";
            }
        }
    }

    /**
     * Data scope + branch / employee / customer filters on the given columns.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(string $branchCol, ?string $employeeCol, ?string $customerCol = null): array
    {
        [$sql, $params] = $this->scope->where($branchCol, $employeeCol);
        foreach ([[$this->branchId, $branchCol], [$this->employeeId, $employeeCol], [$this->customerId, $customerCol]] as [$v, $col]) {
            if ($v !== null) {
                if ($col === null) {
                    return ['1 = 0', []];         // filter cannot apply to this kind of row
                }
                $sql .= " AND {$col} = ?";
                $params[] = $v;
            }
        }
        return [$sql, $params];
    }

    /** @return array<string, string> for links and export URLs */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from, 'to' => $this->to, 'as_on' => $this->asOn,
            'branch' => $this->branchId, 'employee' => $this->employeeId, 'customer' => $this->customerCode,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
