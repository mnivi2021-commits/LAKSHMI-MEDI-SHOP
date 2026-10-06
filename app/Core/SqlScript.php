<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/** Runs .sql files statement by statement (used by cli/install.php and cli/migrate.php). */
final class SqlScript
{
    /**
     * @return int number of statements executed
     * @throws RuntimeException with the failing statement number and a short preview
     */
    public static function runFile(PDO $pdo, string $file): int
    {
        $sql = @file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read ' . basename($file));
        }

        $n = 0;
        foreach (self::split($sql) as $i => $stmt) {
            try {
                $pdo->exec($stmt);
                $n++;
            } catch (PDOException $e) {
                $preview = preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 160));
                throw new RuntimeException(sprintf('%s statement #%d failed: %s | %s...', basename($file), $i + 1, $e->getMessage(), $preview), 0, $e);
            }
        }
        return $n;
    }

    /**
     * Splits a SQL script on top-level semicolons, respecting quotes and comments.
     * Stored procedures / triggers with BEGIN...END bodies are not supported here.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    if ($next === $quote) {      // doubled-quote escape
                        $buf .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
