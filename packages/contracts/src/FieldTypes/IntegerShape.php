<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `integer` field: an integer from $min to $max, each optional, with
 * an optional unit of 1 to 20 characters that the panel shows.
 */
#[Experimental]
final readonly class IntegerShape implements FieldShape
{
    /**
     * @throws InvalidFieldShape when the minimum is above the maximum or the unit is out of range
     */
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
        public ?string $unit = null,
    ) {
        ShapeRules::order('an integer shape', 'value', $min, $max);
        ShapeRules::text('an integer shape', 'unit', $unit, 20);
    }

    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Integer;
    }
}
