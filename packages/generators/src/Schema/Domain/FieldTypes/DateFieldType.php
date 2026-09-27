<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `date`, with optional bounds as full dates.
 */
#[Internal]
final readonly class DateFieldType implements FieldType
{
    public const string NAME = 'date';

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
    public function options(FieldValues $field): DateOptions
    {
        return new DateOptions(
            $field->optionalString('min'),
            $field->optionalString('max'),
        );
    }
}
