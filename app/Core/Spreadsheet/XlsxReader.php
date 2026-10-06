<?php

declare(strict_types=1);

namespace App\Core\Spreadsheet;

use RuntimeException;
use XMLReader;

/**
 * Reads the FIRST worksheet of an .xlsx file into rows of strings.
 *
 *  - shared strings, inline strings, booleans and formulas' cached values are supported
 *  - date-formatted numeric cells come back as "YYYY-MM-DD" (or "YYYY-MM-DD HH:MM")
 *  - other numbers are normalised ("1250", "0.3" - no float noise like 0.30000000000000004)
 *  - XML is parsed without network access; external entities are never loaded
 */
final class XlsxReader
{
    /** Built-in Excel number formats that are dates/times. */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /** @return list<list<string>> */
    public static function read(string $bytes, int $maxRows): array
    {
        $zip = Zip::open($bytes);
        $sheetPath = self::firstSheetPath($zip);
        $shared = $zip->has('xl/sharedStrings.xml') ? self::sharedStrings($zip->read('xl/sharedStrings.xml')) : [];
        $dateStyles = $zip->has('xl/styles.xml') ? self::dateStyles($zip->read('xl/styles.xml')) : [];
        return self::sheet($zip->read($sheetPath), $shared, $dateStyles, $maxRows);
    }

    private static function firstSheetPath(Zip $zip): string
    {
        if (!$zip->has('xl/workbook.xml')) {
            throw new RuntimeException('This is not an Excel workbook (.xlsx).');
        }
        $wb = self::xml($zip->read('xl/workbook.xml'));
        $sheet = $wb->sheets->sheet[0] ?? null;
        if ($sheet === null) {
            throw new RuntimeException('The workbook has no sheets.');
        }
        $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        if ($rid !== '' && $zip->has('xl/_rels/workbook.xml.rels')) {
            $rels = self::xml($zip->read('xl/_rels/workbook.xml.rels'));
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $path = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    if ($zip->has($path)) {
                        return $path;
                    }
                }
            }
        }
        if ($zip->has('xl/worksheets/sheet1.xml')) {
            return 'xl/worksheets/sheet1.xml';
        }
        throw new RuntimeException('Could not find the first worksheet.');
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        $out = [];
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        self::seek($r, 'si');
        while ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
            $node = self::xml($r->readOuterXml());
            $text = '';
            foreach ($node->xpath('.//*[local-name()="t"][not(ancestor::*[local-name()="rPh"])]') ?: [] as $t) {
                $text .= (string) $t;
            }
            $out[] = $text;
            if (!$r->next('si')) {
                break;
            }
        }
        $r->close();
        return $out;
    }

    /** @return array<int, bool> style index => is a date format */
    private static function dateStyles(string $xml): array
    {
        $doc = self::xml($xml);
        $custom = [];
        foreach ($doc->numFmts->numFmt ?? [] as $f) {
            $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', (string) $f['formatCode']));
            $custom[(int) $f['numFmtId']] = (bool) preg_match('/[dmy]|h:|:s/', $code);
        }
        $out = [];
        $i = 0;
        foreach ($doc->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $out[$i++] = in_array($id, self::DATE_FORMAT_IDS, true) || ($custom[$id] ?? false);
        }
        return $out;
    }

    /**
     * @param list<string> $shared
     * @param array<int, bool> $dateStyles
     * @return list<list<string>>
     */
    private static function sheet(string $xml, array $shared, array $dateStyles, int $maxRows): array
    {
        $rows = [];
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rowIndex = 0;
        self::seek($r, 'row');
        while ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
            $rowNum = (int) $r->getAttribute('r') ?: $rowIndex + 1;
            $rowIndex = $rowNum;
            if (count($rows) >= $maxRows + 1 && $rowNum > 1) {
                throw new RuntimeException("The file has more than {$maxRows} data rows. Split it into smaller files.");
            }
            $row = self::xml($r->readOuterXml());
            $cells = [];
            $col = 0;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = $ref !== '' ? self::columnIndex($ref) : $col + 1;
                $cells[$col] = self::cellValue($c, $shared, $dateStyles);
            }
            $more = $r->next('row');
            if ($cells !== []) {
                $line = array_fill(0, max(array_keys($cells)), '');
                foreach ($cells as $i => $v) {
                    $line[$i - 1] = $v;
                }
                $rows[$rowNum] = $line;
            }
            if (!$more) {
                break;
            }
        }
        $r->close();

        // Keep spreadsheet row numbers stable (blank rows are reported, not shifted).
        if ($rows === []) {
            return [];
        }
        $out = [];
        for ($n = 1, $last = max(array_keys($rows)); $n <= $last; $n++) {
            $out[] = $rows[$n] ?? [];
        }
        return $out;
    }

    /** @param list<string> $shared @param array<int, bool> $dateStyles */
    private static function cellValue(\SimpleXMLElement $c, array $shared, array $dateStyles): string
    {
        $type = (string) $c['t'];
        $v = isset($c->v) ? (string) $c->v : '';
        switch ($type) {
            case 's':
                return trim($shared[(int) $v] ?? '');
            case 'inlineStr':
                $text = '';
                foreach ($c->is->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $text .= (string) $t;
                }
                return trim($text);
            case 'b':
                return $v === '1' ? 'TRUE' : 'FALSE';
            case 'str':
            case 'e':
                return trim($v);
        }
        if ($v === '' || !is_numeric($v)) {
            return trim($v);
        }
        if ($dateStyles[(int) $c['s']] ?? false) {
            return self::excelDate((float) $v);
        }
        return self::number($v);
    }

    /** Advance to the first element with this local name (or the end). */
    private static function seek(XMLReader $r, string $name): void
    {
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === $name) {
                return;
            }
        }
    }

    /** Excel serial (1900 system) -> "YYYY-MM-DD" or "YYYY-MM-DD HH:MM". */
    public static function excelDate(float $serial): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $ts = ($days - 25569) * 86400 + $seconds;      // 25569 = days from 1899-12-30 to 1970-01-01
        return $seconds > 0 ? gmdate('Y-m-d H:i', $ts) : gmdate('Y-m-d', $ts);
    }

    public static function number(string $v): string
    {
        if (preg_match('/^-?\d+$/', $v)) {
            return $v;
        }
        $s = number_format((float) $v, 6, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        if ($n < 1 || $n > 200) {
            throw new RuntimeException('The sheet has too many columns.');
        }
        return $n;
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new RuntimeException('The spreadsheet contains invalid XML.');
        }
        return $doc;
    }
}
