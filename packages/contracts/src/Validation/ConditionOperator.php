<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The operators of a field's condition (gap G3): a comparison of one field's value, or all or any
 * of other conditions. There are no expressions.
 */
#[Experimental]
enum ConditionOperator: string
{
    /** The field's value is the value given. */
    case Eq = 'eq';

    /** The field's value is not the value given; a field without a value is not it either. */
    case Neq = 'neq';

    /** The field's value is one of the values given. */
    case In = 'in';

    /** The field has no value: it is left out or null. */
    case Null = 'null';

    /** The field has a value. */
    case NotNull = 'not_null';

    /** Every condition holds. */
    case All = 'all';

    /** At least one condition holds. */
    case Any = 'any';

    /**
     * Whether the operator compares a field, rather than combining conditions.
     */
    public function comparesField(): bool
    {
        return $this !== self::All && $this !== self::Any;
    }

    /**
     * Whether the operator takes values to compare with: one for eq and neq, one or more for in.
     */
    public function takesValues(): bool
    {
        return in_array($this, [self::Eq, self::Neq, self::In], true);
    }
}
