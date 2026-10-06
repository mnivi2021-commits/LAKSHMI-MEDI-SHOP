<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\DataIntegrity;
use DateTimeImmutable;
use Throwable;

/**
 * Environment + database self-test used by /health, /api/health and cli/health.php.
 * Messages never include credentials or absolute file paths.
 */
final class HealthCheck
{
    public const EXPECTED_TABLES = [
        'api_tokens', 'audit_logs', 'branches', 'collection_allocations', 'collections', 'customers',
        'dc_items', 'dc_records', 'departments', 'designations', 'email_accounts', 'email_activity',
        'email_assignments', 'email_attachments', 'email_categories', 'email_messages', 'employees',
        'financial_years', 'followups', 'import_batches', 'import_rows', 'lead_sources', 'leads',
        'login_attempts', 'notifications', 'number_sequences', 'outstanding_bills', 'pending_order_items',
        'pending_orders', 'permissions', 'products', 'role_permissions', 'roles', 'sales_invoice_items',
        'sales_invoices', 'sales_targets', 'sample_items', 'samples', 'schema_migrations', 'settings',
        'sms_campaigns', 'sms_logs', 'sms_messages', 'sms_templates', 'user_branches', 'user_permissions', 'users',
    ];

    public const EXPECTED_VIEWS = [
        'v_invoice_balances', 'v_outstanding_latest', 'v_pending_dc_lines', 'v_pending_order_lines',
        'v_pending_sample_lines', 'v_sales_documents', 'v_sales_lines', 'v_valid_collections',
    ];

    /** @var list<array{group: string, name: string, status: string, message: string}> */
    private array $checks = [];

    /** @return array{status: string, generated_at: string, checks: list<array<string,string>>, financial_year: array<string,mixed>} */
    public function run(): array
    {
        $this->checkPhp();
        $this->checkConfig();
        $this->checkFilesystem();
        $this->checkDatabase();

        $statuses = array_column($this->checks, 'status');
        $overall = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        $fy = FinancialYear::current();
        $ranges = [
            'fy_to_previous_day'    => $fy->fyToPreviousDay()?->label() ?? '— (first day of FY)',
            'month_to_previous_day' => $fy->monthToPreviousDay()?->label() ?? '— (first day of month)',
            'today'                 => $fy->today()->label(),
        ];

        return [
            'status'         => $overall,
            'generated_at'   => (new DateTimeImmutable())->format('d-m-Y H:i:s'),
            'checks'         => $this->checks,
            'financial_year' => ['label' => $fy->label(), 'range' => $fy->fullYear()->label()] + $ranges,
        ];
    }

    private function add(string $group, string $name, string $status, string $message): void
    {
        $this->checks[] = compact('group', 'name', 'status', 'message');
    }

    private function checkPhp(): void
    {
        $ok = version_compare(PHP_VERSION, '8.1.0', '>=');
        $this->add('PHP', 'PHP version', $ok ? 'ok' : 'fail', PHP_VERSION . ($ok ? '' : ' (8.1+ required)'));

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json'] as $ext) {
            $loaded = extension_loaded($ext);
            $this->add('PHP', "ext-{$ext}", $loaded ? 'ok' : 'fail', $loaded ? 'Loaded' : 'Missing - enable in php.ini');
        }
        foreach (['zip' => 'Excel .xlsx import (Phase 14)', 'gd' => 'PDF/Excel export images'] as $ext => $why) {
            $loaded = extension_loaded($ext);
            $this->add('PHP', "ext-{$ext}", $loaded ? 'ok' : 'warn', $loaded ? 'Loaded' : "Not loaded - needed later for {$why}");
        }

        $tz = date_default_timezone_get();
        $this->add('PHP', 'Timezone', $tz === Config::get('app.timezone') ? 'ok' : 'warn', $tz);
    }

    private function checkConfig(): void
    {
        $envFile = is_file(BASE_PATH . '/.env');
        $this->add('Config', '.env file', $envFile ? 'ok' : 'fail', $envFile ? 'Present' : 'Missing - copy .env.example to .env');

        $key = (string) Config::get('app.key', '');
        $this->add('Config', 'APP_KEY', strlen($key) >= 32 ? 'ok' : 'warn', strlen($key) >= 32 ? 'Set' : 'Not set or too short (32+ chars)');

        $env = (string) Config::get('app.env');
        $debug = Config::get('app.debug') === true;
        if ($env === 'production' && $debug) {
            $this->add('Config', 'Debug mode', 'fail', 'APP_DEBUG must be false in production');
        } else {
            $this->add('Config', 'Environment', 'ok', $env . ($debug ? ' (debug on)' : ''));
        }

        $smsGateway = (string) Config::get('services.sms.gateway');
        $this->add('Config', 'SMS gateway', $smsGateway === 'mock' ? 'ok' : 'warn',
            $smsGateway === 'mock' ? 'mock (no real SMS will be sent)' : "{$smsGateway} - real SMS may be sent");
    }

    private function checkFilesystem(): void
    {
        foreach (['logs', 'uploads/imports', 'uploads/attachments'] as $dir) {
            $path = BASE_PATH . '/' . $dir;
            $writable = is_dir($path) && is_writable($path);
            $this->add('Storage', $dir . '/', $writable ? 'ok' : 'fail', $writable ? 'Writable' : 'Not writable');
        }

        // Sensitive directories must live outside the web root (public/).
        $public = realpath(BASE_PATH . '/public') ?: '';
        $leaks = array_filter(['.env', 'uploads', 'logs', 'config', 'database'], static fn (string $p): bool => file_exists($public . '/' . $p));
        $this->add('Storage', 'Web root isolation', $leaks ? 'fail' : 'ok',
            $leaks ? 'Exposed inside public/: ' . implode(', ', $leaks) : 'Secrets, uploads and logs are outside public/');
    }

    private function checkDatabase(): void
    {
        try {
            $pdo = Database::connection();
        } catch (Throwable $e) {
            Logger::exception($e, 'warning');
            $code = (string) $e->getCode();
            $hint = match (true) {
                $code === '1045' => 'Access denied - check DB_USERNAME / DB_PASSWORD in .env',
                $code === '1049' => 'Database not found - run: php cli/install.php',
                $code === '2002' => 'Cannot reach MySQL - is the MySQL service running on DB_HOST:DB_PORT?',
                default          => 'Connection failed (details in logs/)',
            };
            $this->add('Database', 'Connection', 'fail', $hint);
            return;
        }

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $okVersion = !$isMaria && version_compare($version, '8.0.0', '>=');
        $this->add('Database', 'Connection', 'ok', 'Connected to ' . Config::get('database.database'));
        $this->add('Database', 'Server version', $okVersion ? 'ok' : 'warn',
            ($isMaria ? 'MariaDB ' : 'MySQL ') . $version . ($okVersion ? '' : ' - MySQL 8.0+ recommended'));

        $objects = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $missing = array_diff(self::EXPECTED_TABLES, array_keys($objects, 'BASE TABLE', true));
        $this->add('Database', 'Schema', $missing ? 'fail' : 'ok',
            $missing ? count($missing) . ' table(s) missing: ' . implode(', ', array_slice($missing, 0, 6)) . (count($missing) > 6 ? '...' : '')
                     : count(self::EXPECTED_TABLES) . ' tables present');
        $missingViews = array_diff(self::EXPECTED_VIEWS, array_keys($objects, 'VIEW', true));
        $this->add('Database', 'KPI views', $missingViews ? 'fail' : 'ok',
            $missingViews ? 'Missing: ' . implode(', ', $missingViews) : count(self::EXPECTED_VIEWS) . ' views present');
        if ($missing || $missingViews) {
            return;
        }

        $pending = array_filter((new Migrator($pdo, BASE_PATH . '/database/migrations'))->status(),
            static fn (array $m): bool => $m['state'] !== 'applied');
        $this->add('Database', 'Migrations', $pending ? 'warn' : 'ok',
            $pending ? count($pending) . ' pending/changed - run: php cli/migrate.php' : 'Up to date');

        $engines = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB' AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
        $this->add('Database', 'Storage engine', $engines === 0 ? 'ok' : 'warn', $engines === 0 ? 'All tables InnoDB' : "{$engines} non-InnoDB table(s)");

        $fks = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()')->fetchColumn();
        $this->add('Database', 'Foreign keys', $fks > 0 ? 'ok' : 'warn', "{$fks} foreign key constraints");

        $roles = (int) Database::value('SELECT COUNT(*) FROM roles');
        $users = (int) Database::value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL');
        $this->add('Database', 'Seed data', $roles > 0 && $users > 0 ? 'ok' : 'warn',
            "{$roles} roles, {$users} users" . ($users === 0 ? ' - run: php cli/install.php --seed' : ''));

        $fy = FinancialYear::current();
        $row = Database::fetch('SELECT label FROM financial_years WHERE start_date = ?', [$fy->start->format('Y-m-d')]);
        $this->add('Database', 'Current financial year', $row ? 'ok' : 'warn',
            $row ? $row['label'] . ' configured' : $fy->label() . ' not yet in financial_years table');

        $dbToday = (string) Database::value('SELECT CURDATE()');
        $phpToday = date('Y-m-d');
        $this->add('Database', 'Date sync (PHP vs MySQL)', $dbToday === $phpToday ? 'ok' : 'fail',
            $dbToday === $phpToday ? "Both report {$phpToday}" : "PHP {$phpToday} vs MySQL {$dbToday} - check time zones");

        $results = (new DataIntegrity())->run();
        $errors = array_filter($results, static fn (array $r): bool => $r['count'] > 0 && $r['severity'] === 'error');
        $warnings = array_filter($results, static fn (array $r): bool => $r['count'] > 0 && $r['severity'] === 'warning');
        $this->add('Data', 'Integrity rules', $errors ? 'fail' : ($warnings ? 'warn' : 'ok'),
            $errors || $warnings
                ? count($errors) . ' error rule(s), ' . count($warnings) . ' warning rule(s) - run: php cli/verify-data.php'
                : count($results) . ' rules passed');
    }
}
