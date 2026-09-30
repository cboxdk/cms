<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A value in the form of a core `boolean` field.
 */
#[Experimental]
final readonly class BooleanShape implements FieldShape
{
    #[Override]
    public function base(): FieldBase
    {
        return FieldBase::Boolean;
    }
}
