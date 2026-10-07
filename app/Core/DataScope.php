<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Row-level visibility ("whose records may this user see?"), from roles.data_scope:
 *
 *   all     every branch and employee
 *   branch  rows whose branch_id is in the user's user_branches
 *   team    rows whose employee_id is the user's employee or anyone reporting
 *           to them (directly or indirectly)
 *   own     rows whose employee_id is the user's own employee
 *
 * Every KPI, list and report query adds DataScope::for($user)->where(...) so the
 * same rule applies everywhere, including the mobile API. A user who cannot be
 * resolved (e.g. "own" without an employee link) sees nothing, never everything.
 */
final class DataScope
{
    /**
     * @param list<int>|null $branchIds   null = no branch restriction
     * @param list<int>|null $employeeIds null = no employee restriction
     */
    private function __construct(
        public readonly string $scope,
        public readonly ?array $branchIds,
        public readonly ?array $employeeIds,
    ) {}

    public static function for(array $user): self
    {
        $scope = (string) ($user['data_scope'] ?? 'own');
        $employeeId = isset($user['employee_id']) ? (int) $user['employee_id'] : null;

        return match ($scope) {
            'all'    => new self('all', null, null),
            'branch' => new self('branch', self::assignedBranches((int) $user['id']), null),
            'team'   => new self('team', null, $employeeId ? self::teamOf($employeeId) : []),
            default  => new self('own', null, $employeeId ? [$employeeId] : []),
        };
    }

    public function isUnrestricted(): bool
    {
        return $this->branchIds === null && $this->employeeIds === null;
    }

    /**
     * SQL condition + parameters for a query.
     *   [$sql, $params] = $scope->where('si.branch_id', 'si.employee_id');
     *   "... WHERE {$sql} AND ..."
     *
     * @return array{0: string, 1: list<int>}
     */
    public function where(string $branchColumn, ?string $employeeColumn): array
    {
        if ($this->branchIds !== null) {
            return self::in($branchColumn, $this->branchIds);
        }
        if ($this->employeeIds !== null) {
            if ($employeeColumn === null) {
                return ['1 = 0', []];   // table has no employee column: hide rather than leak
            }
            return self::in($employeeColumn, $this->employeeIds);
        }
        return ['1 = 1', []];
    }

    /**
     * Condition for BRANCH LISTS (drop-downs, branch pages). Branch-scoped users see their
     * assigned branches; team / own scoped users see every branch (the branch names are not
     * private, and their records are still limited by employee).
     *
     * @return array{0: string, 1: list<int>}
     */
    public function branchListWhere(string $idColumn): array
    {
        return $this->branchIds !== null ? self::in($idColumn, $this->branchIds) : ['1 = 1', []];
    }

    public function allowsBranch(int $branchId): bool
    {
        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    public function allowsEmployee(?int $employeeId): bool
    {
        if ($this->employeeIds === null) {
            return true;
        }
        return $employeeId !== null && in_array($employeeId, $this->employeeIds, true);
    }

    /** @return list<int> */
    private static function assignedBranches(int $userId): array
    {
        return array_map('intval', Database::query('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id', [$userId])
            ->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** The employee plus everyone below them in the reporting tree. @return list<int> */
    private static function teamOf(int $employeeId): array
    {
        $ids = Database::query(
            'WITH RECURSIVE team AS (
                 SELECT id, 0 AS depth FROM employees WHERE id = ?
                 UNION ALL
                 SELECT e.id, t.depth + 1 FROM employees e JOIN team t ON e.reporting_manager_id = t.id
                 WHERE t.depth < 20
             )
             SELECT DISTINCT id FROM team ORDER BY id',
            [$employeeId]
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('intval', $ids);
    }

    /** @param list<int> $ids @return array{0: string, 1: list<int>} */
    private static function in(string $column, array $ids): array
    {
        if ($ids === []) {
            return ['1 = 0', []];
        }
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
            throw new \InvalidArgumentException('Invalid column name');
        }
        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}
