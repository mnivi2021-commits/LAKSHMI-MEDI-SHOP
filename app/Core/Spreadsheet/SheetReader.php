<?php

declare(strict_types=1);

namespace App\Core\Spreadsheet;

use RuntimeException;

/**
 * One entry point for uploaded spreadsheets: .xlsx or .csv (comma, semicolon or tab).
 * Returns rows of trimmed strings; row 0 is the header row as typed by the user.
 * The type is decided from the file CONTENT, not the user's file name.
 */
final class SheetReader
{
    /** @return list<list<string>> */
    public static function read(string $path, int $maxRows): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('The file is empty.');
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return XlsxReader::read($bytes, $maxRows);
        }
        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException('Old Excel (.xls) files are not supported. In Excel choose File > Save As > Excel Workbook (.xlsx) or CSV.');
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');   // CSV saved by older Excel
        }
        return self::csv($bytes, $maxRows);
    }

    /** @return list<list<string>> */
    public static function csv(string $bytes, int $maxRows): array
    {
        if (str_contains(substr($bytes, 0, 2048), "\0")) {
            throw new RuntimeException('This does not look like a CSV or Excel file.');
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $firstLine = strtok($bytes, "\r\n") ?: '';
        $delimiter = ',';
        $best = substr_count($firstLine, ',');
        foreach ([';', "\t"] as $d) {
            if (substr_count($firstLine, $d) > $best) {
                $best = substr_count($firstLine, $d);
                $delimiter = $d;
            }
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if (count($rows) > $maxRows) {
                fclose($fh);
                throw new RuntimeException("The file has more than {$maxRows} data rows. Split it into smaller files.");
            }
            $rows[] = ($r === [null]) ? [] : array_map(static fn ($v) => trim((string) $v), $r);
        }
        fclose($fh);
        return $rows;
    }
}
