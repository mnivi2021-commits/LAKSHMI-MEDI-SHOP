<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Response;
use App\Core\Session;
use DateTimeImmutable;

/**
 * Dashboard -> Targets tab. Annual targets by division (PPE, MAAP, TRAINING), by sales area
 * (Madurai, Trichy, TTN, TVL), by sales employee; the sales coordinators with the reps they
 * support; the Admin Head and sales managers. Current month target = annual / 12.
 */
final class TargetController
{
    public static function index(): void
    {
        $user = Auth::user();
        $fy = self::fy();
        $data = self::load((int) $fy['id'], $user);
        Response::view('dashboard/targets', [
            'title'    => 'Targets · Dashboard',
            'flash'    => Session::takeFlash(),
            'fy'       => $fy,
            'fyList'   => Database::fetchAll('SELECT id, label FROM financial_years ORDER BY start_date DESC'),
            'canEdit'  => Gate::allows('targets.add', $user),
            'canEntry' => Gate::allowsAny(['daily_entry.add', 'targets.add'], $user),
            'branch'   => (int) ($_GET['branch'] ?? 0),
        ] + $data);
    }

    public static function edit(): void
    {
        $user = Auth::user();
        $fy = self::fy();
        $data = self::load((int) $fy['id'], $user);
        Response::view('dashboard/targets_edit', [
            'title'  => 'Set annual targets · Dashboard',
            'flash'  => Session::takeFlash(),
            'errors' => Session::pull('_target_errors', []),
            'old'    => Session::pull('_target_old', []),
            'fy'     => $fy,
        ] + $data);
    }

    public static function save(): void
    {
        $user = Auth::user();
        $fy = Database::fetch('SELECT id, label, is_locked FROM financial_years WHERE id = ?', [(int) ($_POST['fy'] ?? 0)]);
        if ($fy === null || (int) $fy['is_locked'] === 1) {
            Session::flash('error', $fy === null ? 'Choose a financial year.' : "{$fy['label']} is locked.");
            Response::redirect('/targets');
            return;
        }
        $data = self::load((int) $fy['id'], $user);
        $divisions = array_column($data['divisions'], 'id');
        $areas = array_column($data['areas'], 'id');
        $employees = array_column($data['employees'], 'id');
        $existing = $data['map'];
        $canEditOld = Gate::allows('targets.edit', $user);

        $wanted = [];   // scope key => [level, division, area, employee, paise]
        $errors = [];
        $read = static function (string $field, string $key, array $parts) use (&$wanted, &$errors): void {
            if (!is_array($_POST['t'] ?? null) || !array_key_exists($field, $_POST['t'])) {
                return;                         // box not on the form: leave that target as it is
            }
            $raw = trim((string) $_POST['t'][$field]);
            if ($raw === '') {
                $wanted[$key] = null;           // blank = no target
                return;
            }
            $p = Money::parse($raw);
            if ($p === null || $p < 0) {
                $errors[$field] = 'Enter an amount like 12,00,000.';
                return;
            }
            $wanted[$key] = $parts + ['paise' => $p];
        };
        foreach (array_column($data['branches'], 'id') as $b) {
            $read("b{$b}", "branch:0:0:0:{$b}", ['level' => 'branch', 'division' => null, 'area' => null, 'employee' => null, 'branch' => (int) $b]);
        }
        foreach ($divisions as $d) {
            foreach ($areas as $a) {
                $read("a{$a}d{$d}", "area:{$d}:{$a}:0:0", ['level' => 'area', 'division' => $d, 'area' => $a, 'employee' => null, 'branch' => null]);
            }
            foreach ($employees as $e) {
                $read("e{$e}d{$d}", "employee:{$d}:0:{$e}:0", ['level' => 'employee', 'division' => $d, 'area' => null, 'employee' => $e, 'branch' => null]);
            }
        }
        foreach ($wanted as $key => $w) {
            $old = $existing[$key] ?? null;
            if ($old !== null && !$canEditOld && ($w === null || $w['paise'] !== $old)) {
                $errors['_'] = 'Some targets are already set; you may add new ones but not change them.';
            }
        }
        if ($errors) {
            $_SESSION['_target_errors'] = $errors;
            $_SESSION['_target_old'] = $_POST['t'] ?? [];
            Session::flash('error', count($errors) . ' box(es) need correcting. Nothing was saved.');
            Response::redirect('/targets/edit?fy=' . $fy['id']);
            return;
        }
        $changed = 0;
        Database::transaction(static function () use ($wanted, $existing, $fy, $user, &$changed): void {
            foreach ($wanted as $key => $w) {
                $old = $existing[$key] ?? null;
                if ($w === null) {
                    if ($old !== null) {
                        Database::query('DELETE FROM annual_targets WHERE financial_year_id = ? AND scope_key = ?', [$fy['id'], $key]);
                        $changed++;
                    }
                    continue;
                }
                if ($old === $w['paise']) {
                    continue;
                }
                Database::query(
                    'INSERT INTO annual_targets (financial_year_id, level, division_id, branch_id, area_id, employee_id, annual_target, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE annual_target = VALUES(annual_target), updated_by = VALUES(updated_by)',
                    [$fy['id'], $w['level'], $w['division'], $w['branch'], $w['area'], $w['employee'], Money::toDecimal($w['paise']), $user['id'], $user['id']]
                );
                $changed++;
            }
        });
        Audit::log('annual_targets.saved', 'targets', null, null, ['fy' => $fy['label'], 'changed' => $changed]);
        Session::flash('success', $changed ? "Annual targets saved for {$fy['label']} ({$changed} change(s))." : 'Nothing changed.');
        Response::redirect('/?fy=' . $fy['id']);
    }

    // =========================================================================

    /** @return array<string, mixed> */
    private static function fy(): array
    {
        $id = (int) ($_GET['fy'] ?? 0);
        $fy = $id ? Database::fetch('SELECT id, label, start_date, end_date FROM financial_years WHERE id = ?', [$id]) : null;
        $fy ??= Database::fetch('SELECT id, label, start_date, end_date FROM financial_years WHERE CURDATE() BETWEEN start_date AND end_date')
            ?? Database::fetch('SELECT id, label, start_date, end_date FROM financial_years ORDER BY start_date DESC LIMIT 1');
        return $fy;
    }

    /** Everything the Targets tab and the edit sheet show. @return array<string, mixed> */
    private static function load(int $fyId, array $user): array
    {
        $scope = DataScope::for($user);
        [$ew, $ep] = $scope->where('e.branch_id', 'e.id');
        $divisions = Database::fetchAll("SELECT id, name FROM divisions WHERE status = 'active' ORDER BY id");
        [$bw, $bp] = $scope->branchListWhere('b.id');
        $branches = Database::fetchAll("SELECT b.id, b.name FROM branches b WHERE b.deleted_at IS NULL AND b.status = 'active' AND {$bw} ORDER BY b.id", $bp);
        $areas = Database::fetchAll("SELECT id, name FROM sales_areas WHERE status = 'active' ORDER BY id");
        $employees = Database::fetchAll(
            "SELECT e.id, e.name, e.short_name, e.area, b.name AS branch FROM employees e JOIN branches b ON b.id = e.branch_id
             WHERE e.deleted_at IS NULL AND e.status = 'active' AND (e.is_sales_rep = 1 OR e.sales_role = 'sales_executive') AND {$ew}
             ORDER BY e.area IS NULL, e.area, e.name", $ep);
        $map = [];
        foreach (Database::fetchAll('SELECT scope_key, annual_target FROM annual_targets WHERE financial_year_id = ?', [$fyId]) as $r) {
            $map[$r['scope_key']] = Money::fromDb($r['annual_target']);
        }
        $coordinators = Database::fetchAll(
            "SELECT c.id, c.name, c.short_name, c.area, b.name AS branch,
                    (SELECT GROUP_CONCAT(COALESCE(r.short_name, r.name) ORDER BY r.name SEPARATOR ', ') FROM employees r
                     WHERE r.coordinator_id = c.id AND r.deleted_at IS NULL AND r.status = 'active') AS reps,
                    (SELECT GROUP_CONCAT(DISTINCT d.name ORDER BY d.id SEPARATOR ', ') FROM employees r
                     JOIN annual_targets t ON t.employee_id = r.id AND t.financial_year_id = ? JOIN divisions d ON d.id = t.division_id
                     WHERE r.coordinator_id = c.id AND r.deleted_at IS NULL) AS divisions
             FROM employees c JOIN branches b ON b.id = c.branch_id
             WHERE c.deleted_at IS NULL AND c.status = 'active' AND c.sales_role = 'sales_coordinator' ORDER BY c.name", [$fyId]);
        $leaders = Database::fetchAll(
            "SELECT u.name, r.name AS role, e.mobile, b.name AS branch
             FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id LEFT JOIN branches b ON b.id = e.branch_id
             WHERE u.deleted_at IS NULL AND u.status = 'active' AND r.slug IN ('admin_head', 'sales_manager')
             ORDER BY r.id, u.name");
        return compact('divisions', 'branches', 'areas', 'employees', 'map', 'coordinators', 'leaders');
    }

    /** "Apr 2026 - Mar 2027" for a financial year row. */
    public static function months(array $fy): string
    {
        return (new DateTimeImmutable($fy['start_date']))->format('M Y') . ' – ' . (new DateTimeImmutable($fy['end_date']))->format('M Y');
    }
}
