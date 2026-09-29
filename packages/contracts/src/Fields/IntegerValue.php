<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A whole number, as an integer field holds it.
 */
#[Experimental]
final readonly class IntegerValue implements FieldValue
{
    public function __construct(public int $value) {}

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
