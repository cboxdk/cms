<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `datetime`, with optional bounds as RFC 3339 times.
 */
#[Internal]
final readonly class DatetimeFieldType implements FieldType
{
    public const string NAME = 'datetime';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['min', 'max'];
    }

    #[Override]
    public function options(FieldValues $field): DatetimeOptions
    {
        return new DatetimeOptions(
            $field->optionalDatetime('min'),
            $field->optionalDatetime('max'),
        );
    }
}
