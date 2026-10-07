<?php

declare(strict_types=1);

namespace App\Modules\Hrm;

use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Response;
use App\Core\Session;

/**
 * HRM → Sales person details. Pick a branch; the sales team is shown in four groups:
 *   Manager · Sales Executives (name, age, date of birth, area; name opens their dashboard,
 *   + ADD opens the target sheet) · Sales Support Admin · Sales Coordinator (with the area's sales persons).
 */
final class SalesTeamController
{
    public const ROLES = [
        'manager'           => 'Manager',
        'sales_executive'   => 'Sales Executive',
        'sales_support'     => 'Sales Support Admin',
        'sales_coordinator' => 'Sales Coordinator',
    ];

    public static function index(): void
    {
        $user = Auth::user();
        $scope = DataScope::for($user);
        [$bw, $bp] = $scope->where('id', null);
        $branches = Database::fetchAll("SELECT id, name, branch_code FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bw} ORDER BY name", $bp);
        $ids = array_map('intval', array_column($branches, 'id'));
        $branch = (int) ($_GET['branch'] ?? 0) ?: null;
        if ($branch !== null && !in_array($branch, $ids, true)) {
            $branch = null;
        }
        if ($branch === null && count($ids) === 1) {
            $branch = $ids[0];
        }

        [$ew, $ep] = $scope->where('e.branch_id', 'e.id');
        $where = "e.deleted_at IS NULL AND e.status = 'active' AND e.sales_role IS NOT NULL AND {$ew}";
        $params = $ep;
        if ($branch !== null) {
            $where .= ' AND e.branch_id = ?';
            $params[] = $branch;
        }
        $rows = Database::fetchAll(
            "SELECT e.id, e.employee_code, e.name, e.short_name, e.mobile, e.email, e.date_of_birth, e.area, e.sales_role,
                    e.branch_id, b.name AS branch, b.branch_code, e.coordinator_id, co.name AS coordinator,
                    TIMESTAMPDIFF(YEAR, e.date_of_birth, CURDATE()) AS age, g.name AS designation
             FROM employees e
             JOIN branches b ON b.id = e.branch_id
             LEFT JOIN employees co ON co.id = e.coordinator_id
             LEFT JOIN designations g ON g.id = e.designation_id
             WHERE {$where}
             ORDER BY b.name, FIELD(e.sales_role, 'manager', 'sales_executive', 'sales_support', 'sales_coordinator'), e.name",
            $params
        );
        $groups = array_fill_keys(array_keys(self::ROLES), []);
        foreach ($rows as $r) {
            $groups[$r['sales_role']][] = $r;
        }
        // Area sales persons of each coordinator
        $team = [];
        foreach ($groups['sales_executive'] as $r) {
            if ($r['coordinator_id'] !== null) {
                $team[(int) $r['coordinator_id']][] = $r;
            }
        }
        Response::view('hrm/sales_team', [
            'title'     => 'Sales person details · HRM',
            'flash'     => Session::takeFlash(),
            'branches'  => $branches,
            'branch'    => $branch,
            'groups'    => $groups,
            'team'      => $team,
            'unset'     => (int) Database::value(
                "SELECT COUNT(*) FROM employees e WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.is_sales_rep = 1 AND e.sales_role IS NULL AND {$ew}"
                . ($branch !== null ? ' AND e.branch_id = ?' : ''), $branch !== null ? array_merge($ep, [$branch]) : $ep),
            'canEdit'   => Gate::allows('hrm.edit', $user),
            'canTarget' => Gate::allows('targets.add', $user),
            'canDash'   => Gate::allows('dashboard.view', $user),
        ]);
    }
}
