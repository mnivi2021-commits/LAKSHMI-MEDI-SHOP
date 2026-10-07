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
use App\Modules\Dashboard\Kpi\BranchPerformance;
use DateTimeImmutable;

/**
 * The + ADD sheets behind Branch Performance: one row per sales representative.
 *
 *   Month sheet (start of month): sales target, collection target, opening outstanding.
 *   Day sheet (each working day): sales & collection totals with NOB / NOC, and the
 *   current pending-order, enquiry, lead, DC, sample and outstanding position.
 */
final class EntryController
{
    /** Day sheet columns: field => [group, heading, type]. type: money | count */
    public const DAY_FIELDS = [
        'sales_value'           => ['Sales', 'Value', 'money'],
        'sales_bills'           => ['Sales', 'NOB', 'count'],
        'sales_customers'       => ['Sales', 'NOC', 'count'],
        'collection_value'      => ['Collection', 'Value', 'money'],
        'collection_bills'      => ['Collection', 'NOB', 'count'],
        'collection_customers'  => ['Collection', 'NOC', 'count'],
        'po_non_stock'          => ['Pending order', 'Non stock', 'money'],
        'po_price_issue'        => ['Pending order', 'Price issue', 'money'],
        'po_doubt'              => ['Pending order', 'Doubt', 'money'],
        'enq_new_customer'      => ['Enquiry pending', 'New cust.', 'count'],
        'enq_new_product'       => ['Enquiry pending', 'New prod.', 'count'],
        'lead_new_customer'     => ['Lead created today', 'New cust.', 'count'],
        'lead_new_product'      => ['Lead created today', 'New prod.', 'count'],
        'dc_order'              => ['Open DC', 'With order', 'money'],
        'dc_mail'               => ['Open DC', 'Mail conf.', 'money'],
        'dc_rep_inform'         => ['Open DC', "Rep's inform", 'money'],
        'sample_returnable'     => ['Samples', 'Returnable', 'money'],
        'sample_non_returnable' => ['Samples', 'Non-return.', 'money'],
        'os_overdue'            => ['Outstanding', 'Overdue', 'money'],
        'os_90'                 => ['Outstanding', '90 days', 'money'],
        'os_150'                => ['Outstanding', '150 days', 'money'],
    ];
    public const MONTH_FIELDS = [
        'sales_target'        => 'Sales target',
        'collection_target'   => 'Collection target',
        'opening_outstanding' => 'Opening outstanding',
    ];

    // =========================================================================
    // Sheet page
    // =========================================================================

    public static function sheet(): void
    {
        $user = Auth::user();
        $today = new DateTimeImmutable('today');
        $branch = (int) ($_GET['branch'] ?? 0) ?: null;
        $scope = DataScope::for($user);
        if ($branch !== null && !$scope->allowsBranch($branch)) {
            $branch = null;
        }
        $canDay = Gate::allows('daily_entry.add', $user);
        $canMonth = Gate::allows('targets.add', $user);
        if (!$canDay && !$canMonth) {
            Gate::authorize('daily_entry.add');
            return;
        }

        $date = self::date((string) ($_GET['date'] ?? '')) ?? $today;
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? new DateTimeImmutable($_GET['month'] . '-01') : $date->modify('first day of this month');
        $reps = self::reps($user, $branch);
        $repIds = array_column($reps, 'id');

        // Month first: until opening figures exist for this month, the month sheet opens first.
        $monthDone = $repIds !== [] && (int) Database::value(
            'SELECT COUNT(*) FROM rep_month_openings WHERE opening_month = ? AND employee_id IN (' . implode(',', array_fill(0, count($repIds), '?')) . ')',
            array_merge([$month->format('Y-m-d')], $repIds)
        ) >= count($repIds);
        $type = ($_GET['type'] ?? '') === 'month' || (($_GET['type'] ?? '') === '' && !$monthDone && $canMonth) || !$canDay ? 'month' : 'day';
        if ($type === 'month' && !$canMonth) {
            $type = 'day';
        }

        $old = Session::pull('_sheet_old', []);
        $values = $type === 'day' ? self::dayValues($repIds, $date) : self::monthValues($repIds, $month);
        if ($old) {
            foreach ($old as $id => $row) {
                $values[(int) $id] = array_map(static fn ($v) => (string) $v, (array) $row);
            }
        }
        [$bw, $bp] = $scope->where('id', null);
        Response::view('dashboard/sheet', [
            'title'     => ($type === 'day' ? 'Daily entry' : 'Month start entry') . ' · Branch Performance',
            'flash'     => Session::takeFlash(),
            'errors'    => Session::pull('_sheet_errors', []),
            'type'      => $type,
            'date'      => $date,
            'month'     => $month,
            'today'     => $today,
            'branch'    => $branch,
            'branches'  => Database::fetchAll("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' AND {$bw} ORDER BY name", $bp),
            'reps'      => $reps,
            'values'    => $values,
            'hints'     => $type === 'day' ? self::latestBefore($repIds, $date) : [],
            'monthDone' => $monthDone,
            'canDay'    => $canDay,
            'canMonth'  => $canMonth,
        ]);
    }

    // =========================================================================
    // Save: day sheet
    // =========================================================================

    public static function saveDay(): void
    {
        $user = Auth::user();
        $date = self::date((string) ($_POST['date'] ?? ''));
        $branch = (int) ($_POST['branch'] ?? 0) ?: null;
        $back = '/entry?type=day&date=' . ($date?->format('Y-m-d') ?? '') . ($branch ? '&branch=' . $branch : '');
        $fy = $date ? self::fy($date) : null;
        if ($date === null || $date > new DateTimeImmutable('today')) {
            Session::flash('error', 'Choose a date that is not in the future.');
            Response::redirect('/entry?type=day');
            return;
        }
        if ($fy === null) {
            Session::flash('error', 'No open financial year covers this date (it may be locked).');
            Response::redirect($back);
            return;
        }

        $reps = array_column(self::reps($user, null), null, 'id');
        $input = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $existing = array_column(Database::fetchAll('SELECT * FROM rep_daily_totals WHERE entry_date = ?', [$date->format('Y-m-d')]), null, 'employee_id');
        $errors = [];
        $clean = [];
        foreach ($input as $id => $row) {
            $id = (int) $id;
            if (!isset($reps[$id]) || !is_array($row)) {
                continue;                                   // not in this user's scope: ignored
            }
            $vals = [];
            $blank = true;
            foreach (self::DAY_FIELDS as $field => [, $head, $kind]) {
                $raw = trim((string) ($row[$field] ?? ''));
                if ($raw !== '') {
                    $blank = false;
                }
                $vals[$field] = self::parse($raw, $kind, "{$id}.{$field}", $errors);
            }
            if ($blank && !isset($existing[$id])) {
                continue;                                   // nothing typed for this rep
            }
            if (isset($existing[$id])) {
                $same = true;
                foreach (self::DAY_FIELDS as $field => [, , $kind]) {
                    $was = $existing[$id][$field];
                    $was = $was === null ? null : ($kind === 'money' ? Money::fromDb($was) : (int) $was);
                    $now = $vals[$field];
                    if ($now === null && in_array($field, ['sales_value', 'sales_bills', 'sales_customers', 'collection_value', 'collection_bills', 'collection_customers', 'lead_new_customer', 'lead_new_product'], true)) {
                        $now = 0;
                    }
                    $same = $same && $was === $now;
                }
                if ($same) {
                    continue;                               // unchanged row: nothing to save
                }
            }
            foreach ([['sales_value', 'sales_bills', 'sales_customers'], ['collection_value', 'collection_bills', 'collection_customers']] as [$v, $b, $c]) {
                $value = $vals[$v] ?? 0;
                $bills = $vals[$b] ?? 0;
                $cust = $vals[$c] ?? 0;
                if ($value > 0 && $bills === 0) {
                    $errors["{$id}.{$b}"] = 'Enter the number of bills.';
                } elseif ($value === 0 && $bills > 0) {
                    $errors["{$id}.{$v}"] = 'Enter the value of these bills.';
                }
                if ($cust > $bills) {
                    $errors["{$id}.{$c}"] = 'Customers cannot be more than bills.';
                } elseif ($bills > 0 && $cust === 0) {
                    $errors["{$id}.{$c}"] = 'Enter the number of customers.';
                }
            }
            if (isset($existing[$id]) && !Gate::allows('daily_entry.edit', $user)) {
                $errors["{$id}.sales_value"] = 'Already entered for this date; you may not change it.';
            }
            $clean[$id] = $vals;
        }
        if ($errors) {
            $_SESSION['_sheet_errors'] = $errors;
            $_SESSION['_sheet_old'] = $input;
            Session::flash('error', count($errors) . ' cell(s) need correcting. Nothing was saved.');
            Response::redirect($back);
            return;
        }
        if ($clean === []) {
            Session::flash('error', 'Nothing was typed.');
            Response::redirect($back);
            return;
        }

        $fields = array_keys(self::DAY_FIELDS);
        Database::transaction(static function () use ($clean, $reps, $date, $fy, $fields, $existing, $user): void {
            foreach ($clean as $id => $vals) {
                $params = [];
                foreach ($fields as $fd) {
                    $v = $vals[$fd];
                    $params[] = $v === null ? null : (self::DAY_FIELDS[$fd][2] === 'money' ? Money::toDecimal($v) : $v);
                }
                // Business-done columns are never NULL: blank = 0. Position columns keep NULL = "no change".
                foreach (['sales_value', 'sales_bills', 'sales_customers', 'collection_value', 'collection_bills', 'collection_customers', 'lead_new_customer', 'lead_new_product'] as $flow) {
                    $i = array_search($flow, $fields, true);
                    $params[$i] ??= self::DAY_FIELDS[$flow][2] === 'money' ? '0.00' : 0;
                }
                $cols = implode(', ', $fields);
                $marks = implode(', ', array_fill(0, count($fields), '?'));
                $upd = implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $fields));
                Database::query(
                    "INSERT INTO rep_daily_totals (financial_year_id, entry_date, employee_id, branch_id, {$cols}, created_by, updated_by)
                     VALUES (?, ?, ?, ?, {$marks}, ?, ?)
                     ON DUPLICATE KEY UPDATE {$upd}, updated_by = VALUES(updated_by)",
                    array_merge([$fy['id'], $date->format('Y-m-d'), $id, $reps[$id]['branch_id']], $params, [$user['id'], $user['id']])
                );
                $new = array_combine($fields, $params);
                Audit::log(isset($existing[$id]) ? 'daily_entry.updated' : 'daily_entry.created', 'daily_entry', $id,
                    isset($existing[$id]) ? array_intersect_key($existing[$id], $new) : null, ['date' => $date->format('Y-m-d')] + array_filter($new, static fn ($v) => $v !== null));
            }
        });
        Session::flash('success', 'Saved ' . count($clean) . ' row(s) for ' . $date->format('d-m-Y') . '. The dashboard is updated.');
        Response::redirect($back);
    }

    // =========================================================================
    // Save: month sheet
    // =========================================================================

    public static function saveMonth(): void
    {
        $user = Auth::user();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_POST['month'] ?? '')) ? new DateTimeImmutable($_POST['month'] . '-01') : null;
        $branch = (int) ($_POST['branch'] ?? 0) ?: null;
        $back = '/entry?type=month&month=' . ($month?->format('Y-m') ?? '') . ($branch ? '&branch=' . $branch : '');
        $fy = $month ? self::fy($month) : null;
        if ($month === null || $fy === null) {
            Session::flash('error', 'Choose a month in an open financial year.');
            Response::redirect('/entry?type=month');
            return;
        }
        $reps = array_column(self::reps($user, null), null, 'id');
        $input = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $existing = self::monthValues(array_keys($reps), $month);
        $errors = [];
        $clean = [];
        foreach ($input as $id => $row) {
            $id = (int) $id;
            if (!isset($reps[$id]) || !is_array($row)) {
                continue;
            }
            $vals = [];
            $blank = true;
            foreach (array_keys(self::MONTH_FIELDS) as $field) {
                $raw = trim((string) ($row[$field] ?? ''));
                $blank = $blank && $raw === '';
                $vals[$field] = self::parse($raw, 'money', "{$id}.{$field}", $errors) ?? 0;
            }
            if ($blank) {
                continue;
            }
            if ($existing[$id]['_exists'] && !Gate::allows('targets.edit', $user)) {
                $errors["{$id}.sales_target"] = 'Already entered for this month; you may not change it.';
            }
            $clean[$id] = $vals;
        }
        if ($errors || $clean === []) {
            $_SESSION['_sheet_errors'] = $errors;
            $_SESSION['_sheet_old'] = $input;
            Session::flash('error', $errors ? count($errors) . ' cell(s) need correcting. Nothing was saved.' : 'Nothing was typed.');
            Response::redirect($back);
            return;
        }
        $m = $month->format('Y-m-d');
        Database::transaction(static function () use ($clean, $reps, $fy, $m, $user): void {
            foreach ($clean as $id => $v) {
                Database::query(
                    'INSERT INTO sales_targets (financial_year_id, employee_id, branch_id, target_month, sales_target, collection_target, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE sales_target = VALUES(sales_target), collection_target = VALUES(collection_target), updated_by = VALUES(updated_by)',
                    [$fy['id'], $id, $reps[$id]['branch_id'], $m, Money::toDecimal($v['sales_target']), Money::toDecimal($v['collection_target']), $user['id'], $user['id']]
                );
                Database::query(
                    'INSERT INTO rep_month_openings (financial_year_id, employee_id, branch_id, opening_month, opening_outstanding, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE opening_outstanding = VALUES(opening_outstanding), updated_by = VALUES(updated_by)',
                    [$fy['id'], $id, $reps[$id]['branch_id'], $m, Money::toDecimal($v['opening_outstanding']), $user['id'], $user['id']]
                );
                Audit::log('month_entry.saved', 'daily_entry', $id, null, ['month' => $m] + array_map([Money::class, 'toDecimal'], $v));
            }
        });
        Session::flash('success', 'Month start figures saved for ' . count($clean) . ' sales employee(s), ' . $month->format('F Y') . '.');
        Response::redirect('/entry?type=day' . ($branch ? '&branch=' . $branch : ''));
    }

    // =========================================================================
    // Drill-down: the sheet rows behind one dashboard figure
    // =========================================================================

    public static function entries(): void
    {
        $user = Auth::user();
        $ctx = DashboardContext::fromRequest($user, array_intersect_key($_GET, array_flip(['fy', 'month', 'branch', 'employee'])));
        $metric = (string) ($_GET['metric'] ?? '');
        $bp = new BranchPerformance($ctx);
        $per = $bp->periods();
        $monthStart = $ctx->asOn->modify('first day of this month')->format('Y-m-d');
        $asOn = $ctx->asOn->format('Y-m-d');
        $prev = $ctx->asOn->modify('-1 day')->format('Y-m-d');
        [$w, $p] = $ctx->where(['branch' => 'x.branch_id', 'employee' => 'x.employee_id']);

        $flows = [
            'sales_fy'          => ['Sales as on previous day', $per['fy_prev'], $ctx->fy->start->format('Y-m-d'), $prev, 'sales'],
            'sales_month'       => ['This month sales', $per['month_prev'], $monthStart, $prev, 'sales'],
            'sales_today'       => ['Today sales', $per['today'], $asOn, $asOn, 'sales'],
            'collection_month'  => ['Collection this month', $per['month_prev'], $monthStart, $prev, 'collection'],
            'collection_today'  => ['Today collection', $per['today'], $asOn, $asOn, 'collection'],
            'lead_new_customer' => ['Leads created - new customer', $per['month'], $monthStart, $asOn, 'lead_new_customer'],
            'lead_new_product'  => ['Leads created - new product', $per['month'], $monthStart, $asOn, 'lead_new_product'],
        ];
        $data = ['metric' => $metric, 'ctx' => $ctx];
        if (isset($flows[$metric])) {
            [$title, $period, $from, $to, $kind] = $flows[$metric];
            $cols = in_array($kind, ['sales', 'collection'], true)
                ? "x.{$kind}_value AS value, x.{$kind}_bills AS nob, x.{$kind}_customers AS noc"
                : "x.{$kind} AS value";
            $cond = in_array($kind, ['sales', 'collection'], true) ? "x.{$kind}_value > 0" : "x.{$kind} > 0";
            $rows = Database::fetchAll(
                "SELECT x.entry_date, {$cols}, e.name AS employee, e.short_name, b.name AS branch, u.name AS entered_by, x.updated_at
                 FROM rep_daily_totals x JOIN employees e ON e.id = x.employee_id JOIN branches b ON b.id = x.branch_id
                 LEFT JOIN users u ON u.id = COALESCE(x.updated_by, x.created_by)
                 WHERE x.entry_date BETWEEN ? AND ? AND {$cond} AND {$w} ORDER BY x.entry_date DESC, e.name LIMIT 2000",
                array_merge([$from, $to], $p)
            );
            $data += ['kind' => 'flow', 'title' => $title, 'period' => $period, 'rows' => $rows, 'count' => in_array($kind, ['sales', 'collection'], true)];
        } elseif ($metric === 'opening') {
            [$ow, $op] = $ctx->where(['branch' => 'o.branch_id', 'employee' => 'o.employee_id']);
            $rows = Database::fetchAll(
                "SELECT o.opening_outstanding AS value, e.name AS employee, e.short_name, b.name AS branch, u.name AS entered_by, o.updated_at
                 FROM rep_month_openings o JOIN employees e ON e.id = o.employee_id JOIN branches b ON b.id = o.branch_id
                 LEFT JOIN users u ON u.id = COALESCE(o.updated_by, o.created_by)
                 WHERE o.opening_month = ? AND {$ow} ORDER BY e.name", array_merge([$monthStart], $op));
            $data += ['kind' => 'opening', 'title' => 'Month opening outstanding', 'period' => $per['month'], 'rows' => $rows];
        } elseif (in_array($metric, BranchPerformance::POSITIONS, true)) {
            $labels = ['po_non_stock' => 'Pending orders - non stock', 'po_price_issue' => 'Pending orders - price issue', 'po_doubt' => 'Pending orders - doubtful',
                'enq_new_customer' => 'Enquiry pending - new customer', 'enq_new_product' => 'Enquiry pending - new product',
                'dc_order' => 'Open DC - with order', 'dc_mail' => 'Open DC - mail confirmation', 'dc_rep_inform' => "Open DC - rep's inform",
                'sample_returnable' => 'Samples - returnable', 'sample_non_returnable' => 'Samples - non-returnable',
                'os_overdue' => 'Overdue payment', 'os_90' => 'Outstanding 91-150 days', 'os_150' => 'Outstanding over 150 days'];
            $data += ['kind' => 'position', 'title' => $labels[$metric], 'period' => 'as on ' . $per['as_on'], 'rows' => $bp->positions()['reps'],
                'isCount' => in_array($metric, BranchPerformance::COUNT_POSITIONS, true)];
        } else {
            Response::error(404, 'Unknown figure.');
            return;
        }
        $data['title_page'] = $data['title'];
        Response::view('dashboard/entries', $data + ['title' => $data['title'] . ' · Branch Performance']);
    }

    // =========================================================================

    /** Active sales representatives in the user's scope (optionally one branch). @return list<array<string, mixed>> */
    private static function reps(array $user, ?int $branch): array
    {
        [$w, $p] = DataScope::for($user)->where('e.branch_id', 'e.id');
        if ($branch !== null) {
            $w .= ' AND e.branch_id = ?';
            $p[] = $branch;
        }
        return Database::fetchAll(
            "SELECT e.id, e.name, e.short_name, e.branch_id, b.name AS branch FROM employees e JOIN branches b ON b.id = e.branch_id
             WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.is_sales_rep = 1 AND {$w} ORDER BY b.name, e.name", $p);
    }

    /** @param list<int> $ids @return array<int, array<string, string>> */
    private static function dayValues(array $ids, DateTimeImmutable $date): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $rows = Database::fetchAll('SELECT * FROM rep_daily_totals WHERE entry_date = ? AND employee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$date->format('Y-m-d')], $ids));
        foreach ($rows as $r) {
            foreach (self::DAY_FIELDS as $f => [, , $kind]) {
                $out[(int) $r['employee_id']][$f] = $r[$f] === null ? '' : ($kind === 'money' ? self::plain($r[$f]) : (string) (int) $r[$f]);
            }
            $out[(int) $r['employee_id']]['_exists'] = '1';
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private static function monthValues(array $ids, DateTimeImmutable $month): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['sales_target' => '', 'collection_target' => '', 'opening_outstanding' => '', '_exists' => false];
        }
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $m = $month->format('Y-m-d');
        foreach (Database::fetchAll("SELECT employee_id, sales_target, collection_target FROM sales_targets WHERE target_month = ? AND employee_id IN ({$in})", array_merge([$m], $ids)) as $t) {
            $out[(int) $t['employee_id']]['sales_target'] = self::plain($t['sales_target']);
            $out[(int) $t['employee_id']]['collection_target'] = self::plain($t['collection_target']);
            $out[(int) $t['employee_id']]['_exists'] = true;
        }
        foreach (Database::fetchAll("SELECT employee_id, opening_outstanding FROM rep_month_openings WHERE opening_month = ? AND employee_id IN ({$in})", array_merge([$m], $ids)) as $o) {
            $out[(int) $o['employee_id']]['opening_outstanding'] = self::plain($o['opening_outstanding']);
            $out[(int) $o['employee_id']]['_exists'] = true;
        }
        return $out;
    }

    /** Latest earlier position per rep (shown as grey hints in blank cells). @param list<int> $ids @return array<int, array<string, string>> */
    private static function latestBefore(array $ids, DateTimeImmutable $date): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = Database::fetchAll('SELECT * FROM rep_daily_totals WHERE entry_date < ? AND entry_date >= ? AND employee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                                    ORDER BY entry_date DESC', array_merge([$date->format('Y-m-d'), $date->modify('-60 days')->format('Y-m-d')], $ids));
        foreach ($rows as $r) {
            foreach (BranchPerformance::POSITIONS as $f) {
                if ($r[$f] !== null && !isset($out[(int) $r['employee_id']][$f])) {
                    $out[(int) $r['employee_id']][$f] = self::DAY_FIELDS[$f][2] === 'money' ? self::plain($r[$f]) : (string) (int) $r[$f];
                }
            }
        }
        return $out;
    }

    /** @param array<string, string> $errors */
    private static function parse(string $raw, string $kind, string $key, array &$errors): ?int
    {
        if ($raw === '') {
            return null;
        }
        if ($kind === 'count') {
            if (!preg_match('/^\d{1,4}$/', $raw)) {
                $errors[$key] = 'Whole number, 0-9999.';
                return null;
            }
            return (int) $raw;
        }
        $p = Money::parse($raw);
        if ($p === null) {
            $errors[$key] = 'Amount like 12500 or 12,500.50.';
        }
        return $p;
    }

    private static function plain(string $decimal): string
    {
        return str_ends_with($decimal, '.00') ? substr($decimal, 0, -3) : $decimal;
    }

    private static function date(string $v): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $d : null;
    }

    /** @return array<string, mixed>|null open financial year containing the date */
    private static function fy(DateTimeImmutable $d): ?array
    {
        return Database::fetch('SELECT id, label FROM financial_years WHERE ? BETWEEN start_date AND end_date AND is_locked = 0', [$d->format('Y-m-d')]);
    }
}
