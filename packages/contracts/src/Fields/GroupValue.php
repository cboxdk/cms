<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * The fields of a group field, by handle, as the group's blueprint declares them.
 */
#[Experimental]
final readonly class GroupValue implements FieldValue
{
    public function __construct(public FieldMap $fields) {}

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->fields->equals($this->fields);
    }
}
