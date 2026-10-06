<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/** Inclusive date range. Use from()/to() as bound SQL parameters: col BETWEEN ? AND ? */
final class DateRange
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('Range end is before start');
        }
    }

    public static function orNull(DateTimeImmutable $start, DateTimeImmutable $end): ?self
    {
        return $end < $start ? null : new self($start, $end);
    }

    public function from(): string { return $this->start->format('Y-m-d'); }
    public function to(): string   { return $this->end->format('Y-m-d'); }

    /** "01-04-2026 to 05-10-2026" (or a single date) */
    public function label(string $format = 'd-m-Y'): string
    {
        return $this->from() === $this->to()
            ? $this->start->format($format)
            : $this->start->format($format) . ' to ' . $this->end->format($format);
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }
}
