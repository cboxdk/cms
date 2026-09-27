<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `decimal`: a number without rounding, with a required precision and
 * scale.
 */
#[Internal]
final readonly class DecimalFieldType implements FieldType
{
    public const string NAME = 'decimal';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['precision', 'scale', 'min', 'max', 'unit'];
    }

    #[Override]
    public function options(FieldValues $field): ?DecimalOptions
    {
        $precision = $field->int('precision');
        $scale = $field->int('scale');

        if ($precision === null || $scale === null) {
            return null;
        }

        return new DecimalOptions(
            $precision,
            $scale,
            $field->optionalDecimal('min'),
            $field->optionalDecimal('max'),
            $field->optionalString('unit'),
        );
    }
}
