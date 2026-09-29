<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A field that is present and holds no value. A field that is absent is not in its FieldMap.
 */
#[Experimental]
final readonly class NullValue implements FieldValue
{
    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self;
    }
}
