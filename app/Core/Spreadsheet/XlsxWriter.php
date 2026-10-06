<?php

declare(strict_types=1);

namespace App\Core\Spreadsheet;

/**
 * Writes a simple .xlsx (no ext-zip needed): one or more sheets of text cells,
 * a bold frozen header row, and sensible column widths. Used for import templates.
 * Every value is written as a text cell, so nothing can become a formula.
 */
final class XlsxWriter
{
    /** @var list<array{name: string, rows: list<list<string>>}> */
    private array $sheets = [];

    /** @param list<list<string|int|float|null>> $rows first row = header */
    public function addSheet(string $name, array $rows): self
    {
        $name = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31);
        $this->sheets[] = ['name' => $name, 'rows' => array_map(static fn (array $r) => array_map(static fn ($v) => (string) ($v ?? ''), $r), $rows)];
        return $this;
    }

    public function toString(): string
    {
        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels'         => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml'            => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml'              => $this->styles(),
        ];
        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheetXml($sheet['rows']);
        }
        return Zip::build($files);
    }

    // -------------------------------------------------------------------------

    private function contentTypes(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach (array_keys($this->sheets) as $i) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $x . '</Types>';
    }

    private function workbook(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $s) {
            $x .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $x . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $n = count($this->sheets);
        for ($i = 1; $i <= $n; $i++) {
            $x .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $x .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $x . '</Relationships>';
    }

    private function styles(): string
    {
        // Style 0 = normal, style 1 = bold header on light grey.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param list<list<string>> $rows */
    private function sheetXml(array $rows): string
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $v) {
                $widths[$i] = min(60, max($widths[$i] ?? 10, mb_strlen($v) + 2));
            }
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($widths) {
            $x .= '<cols>';
            foreach ($widths as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1" style="2"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($rows as $r => $row) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $v) {
                $x .= '<c r="' . self::colName($c) . ($r + 1) . '" t="inlineStr"' . ($r === 0 ? ' s="1"' : ' s="2"') . '><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        return $x . '</sheetData></worksheet>';
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    private static function esc(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
