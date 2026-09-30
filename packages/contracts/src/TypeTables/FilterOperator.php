<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a filter of a type table query compares a column with its values (PRD 8.8). A comparison
 * with a column that holds null is false, so `neq` and `nin` never match a null; null is tested
 * with `null` and `not_null`, which take no value.
 */
#[Experimental]
enum FilterOperator: string
{
    /** The column equals the one value. */
    case Eq = 'eq';

    /** The column holds a value other than the one value. */
    case Neq = 'neq';

    /** The column equals one of the values. */
    case In = 'in';

    /** The column holds a value that is none of the values. */
    case NotIn = 'nin';

    /** The column is below the one value. */
    case Lt = 'lt';

    /** The column is at or below the one value. */
    case Lte = 'lte';

    /** The column is above the one value. */
    case Gt = 'gt';

    /** The column is at or above the one value. */
    case Gte = 'gte';

    /** The column holds null. */
    case IsNull = 'null';

    /** The column holds a value. */
    case IsNotNull = 'not_null';

    /**
     * Whether the operator takes exactly one value; `in` and `nin` take one or more, `null` and
     * `not_null` none.
     */
    public function takesOneValue(): bool
    {
        return match ($this) {
            self::In, self::NotIn, self::IsNull, self::IsNotNull => false,
            default => true,
        };
    }

    /**
     * Whether the operator takes a list of one or more values.
     */
    public function takesValues(): bool
    {
        return $this === self::In || $this === self::NotIn;
    }
}
