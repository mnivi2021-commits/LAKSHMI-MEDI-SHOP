<?php

declare(strict_types=1);

namespace App\Core;

/** CSV downloads that open cleanly in Excel and cannot carry spreadsheet formulas. */
final class Csv
{
    /**
     * @param list<string> $header
     * @param iterable<list<mixed>> $rows
     */
    public static function download(string $filename, array $header, iterable $rows): void
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM: Excel shows ₹ and Indian-language names correctly
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'safe'], $row));
        }
        fclose($out);
    }

    /** Neutralise formula injection (=, +, @, tab, CR, or "-" followed by non-numeric text). */
    public static function safe(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s === '') {
            return '';
        }
        if (in_array($s[0], ['=', '+', '@', "\t", "\r"], true) || ($s[0] === '-' && !is_numeric($s))) {
            return "'" . $s;
        }
        return $s;
    }
}
