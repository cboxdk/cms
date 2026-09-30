<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The limits the shapes share with the options of the core field types in blueprint v1.
 */
#[Internal]
final readonly class ShapeRules
{
    /**
     * @throws InvalidFieldShape when the value is outside the range
     */
    public static function range(string $shape, string $what, ?int $value, int $minimum, ?int $maximum = null): void
    {
        if ($value !== null && ($value < $minimum || ($maximum !== null && $value > $maximum))) {
            throw InvalidFieldShape::because(sprintf(
                'The %s of %s is %s, got %d.',
                $what,
                $shape,
                $maximum === null ? 'at least '.$minimum : sprintf('from %d to %d', $minimum, $maximum),
                $value,
            ));
        }
    }

    /**
     * @throws InvalidFieldShape when the text is empty or longer than the maximum
     */
    public static function text(string $shape, string $what, ?string $value, int $maximum): void
    {
        if ($value !== null && ($value === '' || mb_strlen($value) > $maximum)) {
            throw InvalidFieldShape::because(sprintf('The %s of %s is 1 to %d characters, got "%s".', $what, $shape, $maximum, $value));
        }
    }

    /**
     * @throws InvalidFieldShape when the minimum is above the maximum
     */
    public static function order(string $shape, string $what, ?int $minimum, ?int $maximum): void
    {
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw InvalidFieldShape::because(sprintf('The minimum %s of %s, %d, is above its maximum, %d.', $what, $shape, $minimum, $maximum));
        }
    }
}
