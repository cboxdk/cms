<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A FieldShape whose options break the rules of the core field type it takes the form of, such as
 * a text of more than 10000 characters. cms:generate reports the message as generate_schema_invalid
 * at the field's `options`.
 */
#[Experimental]
final class InvalidFieldShape extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
