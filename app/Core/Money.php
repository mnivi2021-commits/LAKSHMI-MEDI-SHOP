<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Exact rupee arithmetic using integer paise (no floats).
 * DECIMAL(15,2) max 9,99,99,99,99,999.99 = 99,999,999,999,999 paise, well inside PHP_INT_MAX.
 */
final class Money
{
    private const MAX_PAISE = 99_999_999_999_999;

    /** "1,23,456.7" / "123456.70" / "₹ 500" -> paise. Null when not a valid non-negative amount. */
    public static function parse(?string $input): ?int
    {
        if ($input === null) {
            return null;
        }
        $clean = str_replace([',', ' ', '₹'], '', trim($input));
        if (!preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            return null;
        }
        $paise = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        return $paise <= self::MAX_PAISE ? $paise : null;
    }

    /** Signed DECIMAL string from MySQL ("-2360.00", "38.5", null) -> paise. Null/empty = 0. */
    public static function fromDb(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $s = trim((string) $value);
        $negative = str_starts_with($s, '-');
        $paise = self::parse(ltrim($s, '-+'));
        if ($paise === null) {
            throw new InvalidArgumentException("Not a money value: {$s}");
        }
        return $negative ? -$paise : $paise;
    }

    /** Paise -> "123456.70" (for SQL DECIMAL parameters). */
    public static function toDecimal(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Tax on a taxable amount: rate is a percentage string like "18" or "12.5". Rounded half-up to the paisa. */
    public static function percentOf(int $paise, string $rate): int
    {
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($rate), $m)) {
            throw new InvalidArgumentException('Invalid rate');
        }
        $basisPoints = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');   // 18.00% = 1800
        $num = $paise * $basisPoints;
        return intdiv($num + 5000, 10000);
    }

    /** Divide paise as evenly as possible; remainder goes to the LAST part so the parts sum exactly. @return list<int> */
    public static function split(int $paise, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('parts must be >= 1');
        }
        $base = intdiv($paise, $parts);
        $out = array_fill(0, $parts, $base);
        $out[$parts - 1] += $paise - $base * $parts;
        return $out;
    }

    /** Rate x quantity (decimal string, up to 3 places) -> paise, half-up. */
    public static function times(int $paise, string $quantity): int
    {
        if (!preg_match('/^(\d{1,11})(?:\.(\d{1,3}))?$/', $quantity, $m)) {
            throw new InvalidArgumentException('Invalid quantity');
        }
        $milli = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '0', 3, '0');
        return intdiv($paise * $milli * 2 + 1000, 2000);
    }

    /** Divide (e.g. value / quantity) to a paisa rate, half-up. Quantity is a decimal string with up to 3 places. */
    public static function perUnit(int $paise, string $quantity): int
    {
        if (!preg_match('/^(\d{1,11})(?:\.(\d{1,3}))?$/', $quantity, $m)) {
            throw new InvalidArgumentException('Invalid quantity');
        }
        $milli = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '0', 3, '0');
        if ($milli === 0) {
            throw new InvalidArgumentException('Quantity must be > 0');
        }
        return intdiv($paise * 1000 * 2 + $milli, $milli * 2);
    }
}
