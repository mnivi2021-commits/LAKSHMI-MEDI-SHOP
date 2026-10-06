<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Money;
use App\Core\Settings;

/**
 * One report. Rows hold money as integer paise; the totals row is computed here
 * from the same rows that are shown and exported, so screen, CSV and Excel agree.
 *
 * Column types: text, date, money (paise), int, qty (decimal string), pct.
 */
abstract class Report
{
    abstract public function key(): string;
    abstract public function title(): string;
    abstract public function group(): string;
    abstract public function description(): string;
    abstract public function permission(): string;

    /** Filters shown: period, as_on, branch, employee, customer. @return list<string> */
    abstract public function filters(): array;

    /** @return array<string, array{0: string, 1: string}> key => [label, type] */
    abstract public function columns(): array;

    /** @return list<array<string, mixed>> */
    abstract public function rows(ReportFilters $f): array;

    /** Explanation shown under the report (what is counted). */
    public function note(ReportFilters $f): ?string
    {
        return null;
    }

    /** Columns that must not be summed (e.g. rates, ages). @return list<string> */
    public function noTotal(): array
    {
        return [];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    public function totals(array $rows): array
    {
        $out = [];
        foreach ($this->columns() as $k => [, $type]) {
            if (in_array($k, $this->noTotal(), true) || !in_array($type, ['money', 'int', 'qty'], true)) {
                continue;
            }
            if ($type === 'qty') {
                $milli = 0;
                foreach ($rows as $r) {
                    $milli += (int) round((float) ($r[$k] ?? 0) * 1000);
                }
                $out[$k] = rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.');
            } else {
                $out[$k] = array_sum(array_map(static fn ($r) => (int) ($r[$k] ?? 0), $rows));
            }
        }
        return $out;
    }

    protected static function salesCol(): string
    {
        return Settings::salesBasis() === 'total' ? 'total_value' : 'taxable_value';
    }

    protected static function paise(mixed $v): int
    {
        return Money::fromDb($v === null ? null : (string) $v);
    }
}
