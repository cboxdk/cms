<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use DateTimeImmutable;

/**
 * A bound of a `datetime` field: a date-time of RFC 3339 with its offset, such as
 * "2026-01-01T00:00:00Z", by the rule of `format: date-time` and the offset pattern in the
 * blueprint schema v1. It keeps the text as written and the instant it names: the seconds since
 * the Unix epoch in UTC and every digit of the fraction of a second, so two bounds compare as
 * instants whatever their offsets. A leap second, :60, is the first second of the next minute.
 */
#[Internal]
final readonly class BlueprintDatetime
{
    /**
     * A date-time of RFC 3339: date, `T`, time, an optional fraction of a second and the offset,
     * `Z` or `+hh:mm` or `-hh:mm`. `T` and `Z` may be lowercase, as RFC 3339 allows.
     */
    public const string PATTERN = '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt]([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]+))?(?:[Zz]|([+-])([0-9]{2}):([0-9]{2}))\z/';

    /** The seconds since the Unix epoch of the instant, in UTC. */
    public int $seconds;

    /** The digits of the fraction of a second, without trailing zeros; empty for a whole second. */
    public string $fraction;

    /**
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw $this->invalid($value);
        }

        [$year, $month, $day, $hour, $minute, $second] = array_map(intval(...), array_slice($parts, 1, 6));
        $offsetHours = (int) ($parts[9] ?? 0);
        $offsetMinutes = (int) ($parts[10] ?? 0);

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 60 || $offsetHours > 23 || $offsetMinutes > 59) {
            throw $this->invalid($value);
        }

        $minuteStart = new DateTimeImmutable(sprintf('%04d-%02d-%02dT%02d:%02d:00+00:00', $year, $month, $day, $hour, $minute))->getTimestamp();
        $offset = ($parts[8] === '-' ? -1 : 1) * ($offsetHours * 3600 + $offsetMinutes * 60);

        $this->seconds = $minuteStart + $second - $offset;
        $this->fraction = rtrim($parts[7] ?? '', '0');
    }

    private function invalid(string $value): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
            '"%s" is not a time of RFC 3339 with an offset. Write the date, T, the time and the offset, such as "2026-01-01T00:00:00Z" or "2026-01-01T01:00:00+01:00".',
            $value,
        ));
    }
}
