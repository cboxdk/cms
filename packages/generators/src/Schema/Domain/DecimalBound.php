<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * A bound of a `decimal` field: a decimal number as a string such as "-12.50", by the pattern of
 * `min` and `max` in the blueprint schema v1, so it is never read as a float and keeps every digit.
 */
#[Internal]
final readonly class DecimalBound
{
    /** An optional minus, digits, and optionally a point with more digits. */
    public const string PATTERN = '/\A-?[0-9]+(?:\.[0-9]+)?\z/';

    /**
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                '"%s" is not a decimal number. Write digits with an optional minus and decimal point, such as "-12.50".',
                $value,
            ));
        }
    }
}
