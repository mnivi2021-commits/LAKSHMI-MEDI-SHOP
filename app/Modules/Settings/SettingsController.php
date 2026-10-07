<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use DateTimeImmutable;

/**
 * System settings (Admin Head / settings.manage). Only business choices live
 * here; secrets (database, SMS keys, mail passwords) stay in .env and are never
 * shown or stored in the database.
 */
final class SettingsController
{
    /** group.key => [label, type, choices|null, help] */
    public const FIELDS = [
        'company.name'               => ['Company name', 'text', null, 'Shown in the header, reports and SMS ({company}).'],
        'finance.sales_amount_basis' => ['Sales figures use', 'choice', ['taxable' => 'Taxable value (excluding GST)', 'total' => 'Invoice total (including GST)'],
                                         'Changes every sales figure on the dashboard, Sales Details and reports.'],
        'outstanding.source'         => ['Outstanding comes from', 'choice', ['computed' => 'CRM invoices minus receipts and credit notes', 'imported' => 'Latest outstanding statement uploaded from accounts'],
                                         'Use "imported" only after uploading a full outstanding statement (Excel Upload).'],
        'outstanding.aging_basis'    => ['Age bills from', 'choice', ['invoice_date' => 'Invoice date', 'due_date' => 'Due date'],
                                         'Decides which bills fall in the 90 DAYS and 150 DAYS boxes.'],
        'targets.sales_commit_pct'   => ['Month start: sales commitment %', 'pct', null, 'Shown beside each sales target on the month start sheet (e.g. 80 = 80% of the target).'],
        'targets.collection_pct'     => ['Month start: collection target %', 'pct', null, 'Collection target = this % of the opening outstanding (e.g. 60).'],
        'outstanding.aging_buckets'  => ['Ageing columns (days)', 'buckets', null, 'Four increasing limits, e.g. 30, 60, 90, 150 → 0-30, 31-60, 61-90, 91-150, 150+.'],
    ];

    public static function index(): void
    {
        if (!Gate::allows('settings.manage')) {
            Response::redirect('/settings/audit');      // audit.view only
            return;
        }
        $values = [];
        foreach (array_keys(self::FIELDS) as $k) {
            [$g, $key] = explode('.', $k);
            $values[$k] = Settings::get($g, $key, '');
        }
        $old = Session::pull('_old', []);
        Response::view('settings/index', [
            'title'  => 'Settings',
            'flash'  => Session::takeFlash(),
            'errors' => Session::pull('_errors', []),
            'values' => array_merge($values, $old),
            'years'  => Database::fetchAll(
                "SELECT fy.*, (SELECT COUNT(*) FROM sales_invoices si WHERE si.financial_year_id = fy.id) AS invoices,
                        ? BETWEEN fy.start_date AND fy.end_date AS is_running
                 FROM financial_years fy ORDER BY fy.start_date DESC", [date('Y-m-d')]
            ),
            'importedAsOn' => Database::value('SELECT MAX(as_on_date) FROM outstanding_bills'),
        ]);
    }

    public static function save(): void
    {
        $in = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $in[$k] = trim((string) ($_POST[str_replace('.', '__', $k)] ?? ''));
        }
        $errors = [];
        if ($in['company.name'] === '' || mb_strlen($in['company.name']) > 100) {
            $errors['company.name'] = 'Enter the company name (up to 100 characters).';
        }
        foreach (self::FIELDS as $k => [, $type, $choices]) {
            if ($type === 'choice' && !array_key_exists($in[$k], $choices)) {
                $errors[$k] = 'Choose one of the options.';
            }
        }
        foreach (self::FIELDS as $k => [, $type]) {
            if ($type === 'pct' && !(ctype_digit($in[$k]) && (int) $in[$k] >= 1 && (int) $in[$k] <= 100)) {
                $errors[$k] = 'Enter a whole number from 1 to 100.';
            }
        }
        $limits = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $in['outstanding.aging_buckets'])), 'strlen'));
        $ok = count($limits) === 4 && array_filter($limits, 'ctype_digit') === $limits;
        if ($ok) {
            $limits = array_map('intval', $limits);
            for ($i = 0; $i < 4; $i++) {
                $ok = $ok && $limits[$i] >= 1 && $limits[$i] <= 999 && ($i === 0 || $limits[$i] > $limits[$i - 1]);
            }
        }
        if (!$ok) {
            $errors['outstanding.aging_buckets'] = 'Enter four whole numbers that keep increasing, e.g. 30, 60, 90, 150.';
        } else {
            $in['outstanding.aging_buckets'] = json_encode($limits);
        }
        if (!isset($errors['outstanding.source']) && $in['outstanding.source'] === 'imported' && !Database::value('SELECT 1 FROM outstanding_bills LIMIT 1')) {
            $errors['outstanding.source'] = 'No outstanding statement has been uploaded yet. Upload one first (Excel Upload → Outstanding statement).';
        }
        if ($errors) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old'] = $in;
            Session::flash('error', 'Please correct the highlighted settings. Nothing was changed.');
            Response::redirect('/settings');
            return;
        }

        $changed = [];
        Database::transaction(static function () use ($in, &$changed): void {
            foreach ($in as $k => $v) {
                [$g, $key] = explode('.', $k);
                $old = Database::value('SELECT setting_value FROM settings WHERE setting_group = ? AND setting_key = ?', [$g, $key]);
                if ((string) $old === $v) {
                    continue;
                }
                Database::query(
                    "INSERT INTO settings (setting_group, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
                    [$g, $key, $v, Auth::id()]
                );
                $changed[$k] = ['old' => $old, 'new' => $v];
            }
        });
        Settings::forget();
        foreach ($changed as $k => $c) {
            Audit::log('setting.changed', 'settings', null, [$k => $c['old']], [$k => $c['new']]);
        }
        Session::flash('success', $changed ? count($changed) . ' setting(s) saved: ' . implode(', ', array_map(static fn ($k) => self::FIELDS[$k][0], array_keys($changed))) . '.' : 'Nothing changed.');
        Response::redirect('/settings');
    }

    /** Add the financial year after the latest one (Indian FY: 1 April - 31 March). */
    public static function addYear(): void
    {
        $last = Database::fetch('SELECT * FROM financial_years ORDER BY start_date DESC LIMIT 1');
        $start = $last ? (new DateTimeImmutable($last['end_date']))->modify('+1 day') : new DateTimeImmutable(date('Y') . '-04-01');
        $end = $start->modify('+1 year')->modify('-1 day');
        $label = 'FY ' . $start->format('Y') . '-' . $end->format('y');
        if ($start > new DateTimeImmutable('+2 years')) {
            Session::flash('error', 'Financial years can be added at most two years ahead.');
        } else {
            Database::query('INSERT INTO financial_years (label, start_date, end_date, created_by, updated_by) VALUES (?, ?, ?, ?, ?)',
                [$label, $start->format('Y-m-d'), $end->format('Y-m-d'), Auth::id(), Auth::id()]);
            Audit::log('financial_year.created', 'settings', (int) Database::connection()->lastInsertId(), null, ['label' => $label]);
            Session::flash('success', "{$label} added (" . $start->format('d-m-Y') . ' to ' . $end->format('d-m-Y') . ').');
        }
        Response::redirect('/settings#years');
    }

    /** Lock a finished year so no entries can be added to it (or unlock it). */
    public static function toggleLock(array $p): void
    {
        $fy = Database::fetch('SELECT * FROM financial_years WHERE id = ?', [(int) ($p['id'] ?? 0)]);
        if ($fy === null) {
            Response::error(404, 'Financial year not found.');
            return;
        }
        $today = date('Y-m-d');
        if (!(int) $fy['is_locked'] && $today <= $fy['end_date']) {
            Session::flash('error', "{$fy['label']} has not ended yet; only finished years can be locked.");
            Response::redirect('/settings#years');
            return;
        }
        $new = (int) $fy['is_locked'] ? 0 : 1;
        Database::query('UPDATE financial_years SET is_locked = ?, updated_by = ? WHERE id = ?', [$new, Auth::id(), $fy['id']]);
        Audit::log($new ? 'financial_year.locked' : 'financial_year.unlocked', 'settings', (int) $fy['id'], ['is_locked' => (int) $fy['is_locked']], ['is_locked' => $new]);
        Session::flash('success', $new ? "{$fy['label']} is locked: no entries, imports or quick-adds can be dated in it." : "{$fy['label']} is unlocked.");
        Response::redirect('/settings#years');
    }
}
