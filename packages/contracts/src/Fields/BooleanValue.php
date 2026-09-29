<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * True or false, as a boolean field holds it.
 */
#[Experimental]
final readonly class BooleanValue implements FieldValue
{
    public function __construct(public bool $value) {}

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
