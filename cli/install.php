<?php

declare(strict_types=1);

/*
 * Database installer.
 *
 *   php cli/install.php            create database (if allowed) + schema + base data
 *                                  (roles, permissions, settings ...); then create the
 *                                  first Admin Head with  php cli/create-admin.php
 *   php cli/install.php --seed     ... and load development / demo data
 *   php cli/install.php --fresh --seed
 *                                  DROP every table first (APP_ENV=local only)
 *
 * Uses the DB_* credentials from .env. Refuses to touch a database that
 * already contains tables unless --fresh is given.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\SqlScript;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$args  = array_slice($argv, 1);
$seed  = in_array('--seed', $args, true);
$fresh = in_array('--fresh', $args, true);

$cfg = Config::get('database');
$db  = (string) $cfg['database'];

if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
    fail('DB_DATABASE may only contain letters, digits and underscore.');
}
if ($fresh && Config::get('app.env') !== 'local') {
    fail('--fresh is only allowed when APP_ENV=local.');
}
if ($seed && Config::get('app.env') === 'production') {
    fail('Seed data contains known demo passwords and is not allowed when APP_ENV=production.');
}

out("Marketing CRM installer");
out("Server   : {$cfg['host']}:{$cfg['port']}  user: {$cfg['username']}");
out("Database : {$db}");

try {
    $server = Database::connect($cfg, false);
} catch (PDOException $e) {
    fail('Cannot connect to MySQL: ' . match ((string) $e->getCode()) {
        '1045'  => 'access denied (check DB_USERNAME / DB_PASSWORD in .env)',
        '2002'  => 'server not reachable (is the MySQL service running?)',
        default => $e->getMessage(),
    });
}

$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
out("MySQL    : {$version}");
if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0.0', '<')) {
    fail('MySQL 8.0 or newer is required (the schema uses CHECK constraints, JSON_TABLE and generated columns).');
}

$exists = (bool) $server->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $server->quote($db))->fetchColumn();
if (!$exists) {
    try {
        $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        out("Created database {$db}");
    } catch (PDOException $e) {
        fail("Database {$db} does not exist and this user cannot create it. Create it first (see database/setup_user.sql).");
    }
}

$pdo = Database::connect($cfg);
// TABLE_NAME => 'BASE TABLE' | 'VIEW'
$objects = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);

if ($objects && !$fresh) {
    fail(count($objects) . " table(s)/view(s) already exist in {$db}. Nothing changed. Use cli/migrate.php for schema updates, or --fresh (local only) to rebuild.");
}

if ($objects && $fresh) {
    out('Dropping ' . count($objects) . ' existing table(s)/view(s)...');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($objects as $name => $type) {
        $kind = $type === 'VIEW' ? 'VIEW' : 'TABLE';
        $pdo->exec("DROP {$kind} IF EXISTS `" . str_replace('`', '', $name) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

try {
    out('Applied schema: ' . SqlScript::runFile($pdo, BASE_PATH . '/database/schema.sql') . ' statements');
    (new Migrator($pdo, BASE_PATH . '/database/migrations'))->recordBaseline(BASE_PATH . '/database/schema.sql');
    out('Applied base data: ' . SqlScript::runFile($pdo, BASE_PATH . '/database/base.sql') . ' statements');
    if ($seed) {
        out('Applied seed: ' . SqlScript::runFile($pdo, BASE_PATH . '/database/seed.sql') . ' statements');
    } else {
        // A clean install needs the financial year it is used in (and the next one).
        $startMonth = 4;
        $today = new DateTimeImmutable('today');
        $year = (int) $today->format('Y') - ((int) $today->format('n') < $startMonth ? 1 : 0);
        foreach ([$year, $year + 1] as $y) {
            $start = sprintf('%04d-%02d-01', $y, $startMonth);
            $end = (new DateTimeImmutable($start))->modify('+1 year -1 day')->format('Y-m-d');
            $pdo->prepare('INSERT INTO financial_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, ?)')
                ->execute([sprintf('FY %d-%02d', $y, ($y + 1) % 100), $start, $end, $y === $year ? 1 : 0]);
        }
        out("Created financial years FY {$year}-" . sprintf('%02d', ($year + 1) % 100) . ' and the next one.');
    }
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

$count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
$views = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'")->fetchColumn();
out("Done. {$count} tables and {$views} views installed" . ($seed ? ' with seed data.' : '.'));
if ($seed) {
    out('Demo logins (must change on first login): admin / Admin@2026, coordinator / Coord@2026, jana / Sales@2026');
} else {
    out('Next: create the first Admin Head:  php cli/create-admin.php');
}
out('Next: php cli/verify-data.php, then open ' . Config::get('app.url') . '/health');

// -----------------------------------------------------------------------------

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): never
{
    fwrite(STDERR, 'ERROR: ' . $msg . PHP_EOL);
    exit(1);
}
