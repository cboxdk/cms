<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `decimal` field: a decimal number of $precision digits (1 to 38),
 * $scale of them after the point, from $min to $max, each optional and written as a decimal string
 * such as "-12.50" so no digit is lost, with an optional unit of 1 to 20 characters.
 */
#[Experimental]
final readonly class DecimalShape implements FieldShape
{
    /** An optional minus, digits, and optionally a point with more digits. */
    private const string DECIMAL = '/\A-?[0-9]+(?:\.[0-9]+)?\z/';

    /**
     * @throws InvalidFieldShape when the precision, scale, a bound or the unit is out of range
     */
    public function __construct(
        public int $precision,
        public int $scale,
        public ?string $min = null,
        public ?string $max = null,
        public ?string $unit = null,
    ) {
        ShapeRules::range('a decimal shape', 'precision', $precision, 1, 38);
        ShapeRules::range('a decimal shape', 'scale', $scale, 0, $precision);
        ShapeRules::text('a decimal shape', 'unit', $unit, 20);

        foreach (['minimum' => $min, 'maximum' => $max] as $what => $bound) {
            if ($bound !== null && preg_match(self::DECIMAL, $bound) !== 1) {
                throw InvalidFieldShape::because(sprintf('The %s of a decimal shape is a decimal number such as "-12.50", got "%s".', $what, $bound));
            }
        }
    }

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Decimal;
    }
}
