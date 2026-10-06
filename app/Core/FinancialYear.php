<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Indian financial year (default April-March) and the dashboard date windows.
 *
 * Nothing here is hard-coded to a particular year: every range is derived from
 * the "as on" date passed in (normally today), so the windows roll over
 * automatically at midnight and on 1 April.
 *
 * The three dashboard figures never overlap, so today's sales are never counted twice:
 *   fyToPreviousDay()     FY start  .. yesterday
 *   monthToPreviousDay()  1st of month .. yesterday
 *   today()               today .. today
 * Total = fyToPreviousDay + today.
 */
final class FinancialYear
{
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;
    public readonly DateTimeImmutable $asOn;

    public function __construct(DateTimeImmutable $asOn, int $startMonth = 4)
    {
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException('FY start month must be 1-12');
        }

        $asOn = $asOn->setTime(0, 0);
        $year = (int) $asOn->format('Y');
        if ((int) $asOn->format('n') < $startMonth) {
            $year--;
        }

        $this->asOn  = $asOn;
        $this->start = $asOn->setDate($year, $startMonth, 1);
        $this->end   = $this->start->modify('+1 year')->modify('-1 day');
    }

    public static function current(?int $startMonth = null): self
    {
        return new self(new DateTimeImmutable('today'), $startMonth ?? (int) Config::get('app.fy_start_month', 4));
    }

    public static function forDate(string $date, ?int $startMonth = null): self
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date: {$date}");
        }
        return new self($d, $startMonth ?? (int) Config::get('app.fy_start_month', 4));
    }

    /** "FY 2026-27" */
    public function label(): string
    {
        $endYear = (int) $this->end->format('Y');
        $startYear = (int) $this->start->format('Y');
        return $startYear === $endYear
            ? 'FY ' . $startYear
            : sprintf('FY %d-%02d', $startYear, $endYear % 100);
    }

    /** Short form used in document numbers: "26-27" */
    public function shortLabel(): string
    {
        return sprintf('%02d-%02d', (int) $this->start->format('y'), (int) $this->end->format('y'));
    }

    /** FY start .. yesterday. NULL on the first day of the FY (empty range). */
    public function fyToPreviousDay(): ?DateRange
    {
        return DateRange::orNull($this->start, $this->asOn->modify('-1 day'));
    }

    /** FY start .. today (inclusive). */
    public function fyToDate(): DateRange
    {
        return new DateRange($this->start, $this->asOn);
    }

    /** 1st of current month .. yesterday. NULL on the 1st of a month. */
    public function monthToPreviousDay(): ?DateRange
    {
        return DateRange::orNull($this->asOn->modify('first day of this month'), $this->asOn->modify('-1 day'));
    }

    public function monthToDate(): DateRange
    {
        return new DateRange($this->asOn->modify('first day of this month'), $this->asOn);
    }

    public function today(): DateRange
    {
        return new DateRange($this->asOn, $this->asOn);
    }

    public function fullYear(): DateRange
    {
        return new DateRange($this->start, $this->end);
    }

    /** Whole months elapsed before the current month (for average monthly sales). */
    public function completedMonths(): int
    {
        $diff = $this->start->diff($this->asOn->modify('first day of this month'));
        return $diff->y * 12 + $diff->m;
    }

    /** Months remaining including the current one (for required run-rate). */
    public function remainingMonths(): int
    {
        return 12 - $this->completedMonths();
    }

    public function contains(DateTimeImmutable $date): bool
    {
        $d = $date->setTime(0, 0);
        return $d >= $this->start && $d <= $this->end;
    }
}
