<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The span of one partition. Every span starts at midnight UTC.
 */
#[Experimental]
enum PartitionInterval: string
{
    case Day = 'day';
    case Month = 'month';

    /**
     * The start of the span that holds the instant.
     */
    public function startOf(DateTimeImmutable $instant): DateTimeImmutable
    {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));

        return match ($this) {
            self::Day => $utc->setTime(0, 0),
            self::Month => $utc->setDate((int) $utc->format('Y'), (int) $utc->format('n'), 1)->setTime(0, 0),
        };
    }

    /**
     * The start of the span after the one that starts at $start.
     */
    public function next(DateTimeImmutable $start): DateTimeImmutable
    {
        return match ($this) {
            self::Day => $start->modify('+1 day'),
            self::Month => $start->modify('first day of next month'),
        };
    }

    /**
     * The suffix in a partition's name for the span that starts at $start: 20260101 for a day,
     * 202601 for a month.
     */
    public function suffix(DateTimeImmutable $start): string
    {
        return $start->format($this->suffixFormat());
    }

    /**
     * The start of the span a suffix names, or null when the suffix is not one of this interval's.
     */
    public function parseSuffix(string $suffix): ?DateTimeImmutable
    {
        if (preg_match($this === self::Day ? '/\A\d{8}\z/' : '/\A\d{6}\z/', $suffix) !== 1) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('!'.$this->suffixFormat(), $suffix, new DateTimeZone('UTC'));

        if ($start === false || $start->format($this->suffixFormat()) !== $suffix) {
            return null;
        }

        return $start;
    }

    private function suffixFormat(): string
    {
        return match ($this) {
            self::Day => 'Ymd',
            self::Month => 'Ym',
        };
    }
}
