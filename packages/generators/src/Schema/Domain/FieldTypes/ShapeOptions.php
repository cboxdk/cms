<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\FieldTypes\BooleanShape;
use Cbox\Cms\Contracts\FieldTypes\DateShape;
use Cbox\Cms\Contracts\FieldTypes\DatetimeShape;
use Cbox\Cms\Contracts\FieldTypes\DecimalShape;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;
use Cbox\Cms\Contracts\FieldTypes\InvalidFieldShape;
use Cbox\Cms\Contracts\FieldTypes\LongTextShape;
use Cbox\Cms\Contracts\FieldTypes\SelectChoice;
use Cbox\Cms\Contracts\FieldTypes\SelectShape;
use Cbox\Cms\Contracts\FieldTypes\TextShape;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDatetime;
use Cbox\Cms\Generators\Schema\Domain\DecimalBound;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;

/**
 * The options of the core field type a FieldShape takes the form of, as a blueprint field of that
 * type would have them (PRD 11.12). Every case of FieldBase has its shape here, and the generator
 * coverage test holds every base to a core field type that every generator maps.
 */
#[Internal]
final readonly class ShapeOptions
{
    /**
     * @throws InvalidFieldShape for a shape that is not one of the contracts' shapes
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid for a bound, date, instant or
     *                          choice value of the wrong form
     */
    public static function of(FieldShape $shape): FieldOptions
    {
        return match (true) {
            $shape instanceof TextShape => new TextOptions($shape->minLength, $shape->maxLength, TextFormat::from($shape->format->value)),
            $shape instanceof LongTextShape => new LongTextOptions($shape->minLength, $shape->maxLength),
            $shape instanceof IntegerShape => new IntegerOptions($shape->min, $shape->max, $shape->unit),
            $shape instanceof DecimalShape => new DecimalOptions(
                $shape->precision,
                $shape->scale,
                $shape->min === null ? null : new DecimalBound($shape->min),
                $shape->max === null ? null : new DecimalBound($shape->max),
                $shape->unit,
            ),
            $shape instanceof BooleanShape => new BooleanOptions,
            $shape instanceof DateShape => new DateOptions(
                $shape->min === null ? null : new BlueprintDate($shape->min),
                $shape->max === null ? null : new BlueprintDate($shape->max),
            ),
            $shape instanceof DatetimeShape => new DatetimeOptions(
                $shape->min === null ? null : new BlueprintDatetime($shape->min),
                $shape->max === null ? null : new BlueprintDatetime($shape->max),
            ),
            $shape instanceof SelectShape => new SelectOptions(
                array_map(static fn (SelectChoice $choice): SelectOption => new SelectOption(new Handle($choice->value), $choice->label), $shape->choices),
                $shape->multiple,
                $shape->minItems,
                $shape->maxItems,
            ),
            default => throw InvalidFieldShape::because(sprintf(
                'The shape %s is not one of the shapes of Cbox\Cms\Contracts\FieldTypes: TextShape, LongTextShape, IntegerShape, DecimalShape, BooleanShape, DateShape, DatetimeShape or SelectShape.',
                $shape::class,
            )),
        };
    }
}
