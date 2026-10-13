<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The values a condition reads (gap G3), as the input that holds the conditional field gives them:
 * each runtime that checks conditions, the input validator, the codecs and the seeder, reads its
 * own form of the input through one of these. A field that the input leaves out, or whose object
 * the reference does not reach, has no value.
 */
#[Experimental]
interface ConditionValues
{
    /**
     * Whether the field has no value: it is left out or null.
     */
    public function isNull(FieldReference $field): bool;

    /**
     * Whether the field's value is the value given: text that is the same text, a whole number that
     * is the same number, or the same boolean. A value of another kind is never it.
     */
    public function is(FieldReference $field, string|int|bool $value): bool;
}
