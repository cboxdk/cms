<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A field that a generated DTO leaves out (PRD 8.9, GUARDRAILS 2.2): withheld, because it is
 * classified above the classification access of the caller it was made for (PRD 12.2), or absent
 * from the JSON document it was decoded from, which the contract allows for an optional field.
 *
 * It is never null. Null is a value a field holds; Omitted says the DTO carries no value for the
 * field, so code that reads it must handle the case, and no template mistakes a field it was not
 * given for an empty one. The codec leaves an omitted field out of the JSON it encodes.
 */
#[Experimental]
enum Omitted
{
    case Field;
}
