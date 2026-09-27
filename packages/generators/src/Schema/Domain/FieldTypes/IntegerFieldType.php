<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `integer`.
 */
#[Internal]
final readonly class IntegerFieldType implements FieldType
{
    public const string NAME = 'integer';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['min', 'max', 'unit'];
    }

    #[Override]
    public function options(FieldValues $field): IntegerOptions
    {
        return new IntegerOptions(
            $field->optionalInt('min'),
            $field->optionalInt('max'),
            $field->optionalString('unit'),
        );
    }
}
