<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Core\Request;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** HTML-escape for output. Use on every value printed into a template. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute-path URL inside the app: url('assets/css/app.css') -> /marketing_crm/assets/css/app.css */
function url(string $path = ''): string
{
    return Request::basePath() . '/' . ltrim($path, '/');
}

/**
 * Indian number grouping: 1000000 -> "10,00,000.00".
 * Works on the decimal string, never on floats, so DECIMAL values stay exact.
 */
function inr_number(string|int|float|null $amount, int $decimals = 2): string
{
    $value = is_string($amount) ? trim($amount) : (string) ($amount ?? '0');
    if (!is_numeric($value)) {
        $value = '0';
    }

    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '-+');

    [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
    $int  = $int === '' ? '0' : $int;
    $frac = str_pad($frac, $decimals + 1, '0');

    // Round half-up on the digit string (no float conversion).
    $digits = $int . substr($frac, 0, $decimals);
    if ((int) $frac[$decimals] >= 5) {
        $digits = digit_string_increment($digits);
    }
    $int  = $decimals > 0 ? substr($digits, 0, -$decimals) : $digits;
    $frac = $decimals > 0 ? substr($digits, -$decimals) : '';

    $int = ltrim($int, '0') ?: '0';
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }

    $isZero = trim($int . $frac, '0,') === '';
    return ($negative && !$isZero ? '-' : '') . $int . ($decimals > 0 ? '.' . $frac : '');
}

/** Integer paise -> "₹10,00,000.00" (or with $decimals = 0: "₹10,00,000"). */
function rupees(?int $paise, int $decimals = 0): string
{
    return $paise === null ? '—' : inr(\App\Core\Money::toDecimal($paise), $decimals);
}

/** Integer paise -> short Indian form for axes and tight spaces: ₹1.25 Cr, ₹38.2 L, ₹45.6 K, ₹950. */
function rupees_short(?int $paise): string
{
    if ($paise === null) {
        return '—';
    }
    $sign = $paise < 0 ? '-' : '';
    $r = abs($paise) / 100;
    $fmt = static fn (float $v, string $unit): string => rtrim(rtrim(number_format($v, $v < 10 ? 2 : 1, '.', ''), '0'), '.') . $unit;
    return $sign . '₹' . match (true) {
        $r >= 1e7 => $fmt($r / 1e7, ' Cr'),
        $r >= 1e5 => $fmt($r / 1e5, ' L'),
        $r >= 1e3 => $fmt($r / 1e3, ' K'),
        default   => (string) round($r),
    };
}

/** "0999" -> "1000", "99" -> "100" */
function digit_string_increment(string $digits): string
{
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        if ($digits[$i] !== '9') {
            $digits[$i] = (string) ((int) $digits[$i] + 1);
            return $digits;
        }
        $digits[$i] = '0';
    }
    return '1' . $digits;
}

/** "₹10,00,000.00" */
function inr(string|int|float|null $amount, int $decimals = 2): string
{
    $formatted = inr_number($amount, $decimals);
    return str_starts_with($formatted, '-') ? '-₹' . substr($formatted, 1) : '₹' . $formatted;
}
