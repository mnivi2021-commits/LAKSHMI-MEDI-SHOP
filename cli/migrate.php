<?php

declare(strict_types=1);

/*
 * Schema migrations.
 *
 *   php cli/migrate.php            apply pending database/migrations/*.sql
 *   php cli/migrate.php --status   list applied / pending / changed migrations
 *
 * Back up the database before migrating production:
 *   mysqldump -u root -p marketing_crm > backup.sql
 */

use App\Core\Database;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$migrator = new Migrator(Database::connection(), BASE_PATH . '/database/migrations');

try {
    if (in_array('--status', array_slice($argv, 1), true)) {
        $status = $migrator->status();
        if (!$status) {
            echo "No migration files yet (baseline schema only).\n";
        }
        foreach ($status as $m) {
            printf("[%-7s] %s\n", strtoupper($m['state']), $m['name']);
        }
        $changed = array_filter($status, static fn (array $m): bool => $m['state'] === 'changed');
        if ($changed) {
            echo "\nWARNING: 'changed' files were edited after being applied. Never edit an applied migration -\n";
            echo "add a new migration file instead.\n";
        }
        exit(0);
    }

    $n = $migrator->migrate(static function (string $line): void { echo $line . PHP_EOL; });
    echo $n === 0 ? "Nothing to migrate - database is up to date.\n" : "Done. {$n} migration(s) applied.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
