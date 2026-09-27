<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * A bound of a `date` field: a full date of RFC 3339 such as "2026-01-01", by the rule of
 * `format: date` in the blueprint schema v1, which is a day of the Gregorian calendar in the years
 * 0001 to 9999.
 */
#[Internal]
final readonly class BlueprintDate
{
    /** A full date of RFC 3339: the year, the month and the day. */
    public const string PATTERN = '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/';

    /**
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                '"%s" is not a date. Write a day of the calendar as YYYY-MM-DD, such as "2026-01-01".',
                $value,
            ));
        }
    }
}
