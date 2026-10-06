<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Applies database/migrations/*.sql in filename order and records each one in
 * schema_migrations. File names: YYYY_MM_DD_HHMMSS_short_description.sql
 *
 * MySQL commits DDL implicitly, so a migration cannot be rolled back as a unit:
 * keep each file to one logical change and test it on a copy first.
 */
final class Migrator
{
    public const BASELINE = '0000_00_00_000000_baseline_schema';
    private const NAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.sql$/';

    public function __construct(private readonly PDO $pdo, private readonly string $dir) {}

    /** Called by the installer right after schema.sql is applied. */
    public function recordBaseline(string $schemaFile): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (migration, checksum, batch) VALUES (?, ?, 1)');
        $stmt->execute([self::BASELINE, hash_file('sha256', $schemaFile)]);

        // A fresh install already contains everything up to now - mark existing migrations as applied.
        foreach ($this->files() as $name => $path) {
            $stmt->execute([$name, hash_file('sha256', $path)]);
        }
    }

    /** @return array<string, string> migration name => absolute path, sorted */
    public function files(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $path) {
            $base = basename($path);
            if (!preg_match(self::NAME_PATTERN, $base)) {
                throw new RuntimeException("Badly named migration file: {$base}");
            }
            $files[substr($base, 0, -4)] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, array{checksum: string, batch: int, applied_at: string}> */
    public function applied(): array
    {
        $rows = $this->pdo->query('SELECT migration, checksum, batch, applied_at FROM schema_migrations ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['migration']] = ['checksum' => $r['checksum'], 'batch' => (int) $r['batch'], 'applied_at' => $r['applied_at']];
        }
        return $out;
    }

    /** @return list<array{name: string, state: string}> state: applied | pending | changed */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->files() as $name => $path) {
            $state = 'pending';
            if (isset($applied[$name])) {
                $state = hash_equals($applied[$name]['checksum'], hash_file('sha256', $path)) ? 'applied' : 'changed';
            }
            $out[] = ['name' => $name, 'state' => $state];
        }
        return $out;
    }

    /**
     * @param callable(string): void $log
     * @return int number of migrations applied
     */
    public function migrate(callable $log): int
    {
        $applied = $this->applied();
        if (!isset($applied[self::BASELINE])) {
            throw new RuntimeException('Baseline missing - this database was not installed with cli/install.php.');
        }

        $pending = array_diff_key($this->files(), $applied);
        if (!$pending) {
            return 0;
        }

        $batch = 1 + (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) FROM schema_migrations')->fetchColumn();
        $record = $this->pdo->prepare('INSERT INTO schema_migrations (migration, checksum, batch) VALUES (?, ?, ?)');

        foreach ($pending as $name => $path) {
            $n = SqlScript::runFile($this->pdo, $path);
            $record->execute([$name, hash_file('sha256', $path), $batch]);
            $log("Applied {$name} ({$n} statements)");
        }
        return count($pending);
    }
}
