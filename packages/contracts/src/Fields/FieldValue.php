<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One value of the kernel's generic field-value structure, which holds the fields of any type
 * without the kernel knowing the type (GUARDRAILS 2.4). A plan carries the fields of a revision
 * in it, and the records generated from a blueprint convert to and from it.
 *
 * The values are NullValue, TextValue, IntegerValue, DecimalValue, BooleanValue, DateValue,
 * DateTimeValue, ListValue, GroupValue (fields by handle, as a group field holds them) and MapValue
 * (entries by any string key, for structured content such as rich text). Every value is final
 * readonly and compares by value.
 */
#[Experimental]
interface FieldValue
{
    /**
     * Whether the other value is of the same kind and holds the same value.
     */
    public function equals(self $other): bool;
}
