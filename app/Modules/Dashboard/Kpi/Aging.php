<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Kpi;

use App\Core\Settings;

/**
 * Aging buckets shared by Pending Orders and Outstanding (settings outstanding.aging_buckets,
 * default [30,60,90,150] -> 0-30, 31-60, 61-90, 91-150, 150+).
 */
final class Aging
{
    /** @return list<array{key: string, label: string, min: int, max: ?int}> */
    public static function buckets(): array
    {
        $limits = json_decode((string) Settings::get('outstanding', 'aging_buckets', '[30,60,90,150]'), true);
        if (!is_array($limits) || $limits === []) {
            $limits = [30, 60, 90, 150];
        }
        $limits = array_values(array_unique(array_map('intval', $limits)));
        sort($limits);

        $out = [];
        $min = 0;
        foreach ($limits as $max) {
            $out[] = ['key' => "{$min}-{$max}", 'label' => "{$min}-{$max} days", 'min' => $min, 'max' => $max];
            $min = $max + 1;
        }
        $last = $min - 1;
        $out[] = ['key' => "{$last}+", 'label' => "{$last}+ days", 'min' => $min, 'max' => null];
        return $out;
    }

    /** SQL CASE turning an integer age expression into a bucket key. Contains no user input. */
    public static function caseSql(string $ageExpr): string
    {
        $sql = 'CASE';
        foreach (self::buckets() as $b) {
            $sql .= $b['max'] === null
                ? " ELSE '{$b['key']}'"
                : " WHEN {$ageExpr} <= {$b['max']} THEN '{$b['key']}'";
        }
        return $sql . ' END';
    }

    /** @return array{min: int, max: ?int}|null */
    public static function find(?string $key): ?array
    {
        foreach (self::buckets() as $b) {
            if ($b['key'] === $key) {
                return $b;
            }
        }
        return null;
    }
}
