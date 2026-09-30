<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * An option of a field that is not of the kind the field type reads it as, such as a string where
 * it reads an integer. The options schema should have refused it; cms:generate reports the message
 * as generate_schema_invalid at the field's `options`.
 */
#[Experimental]
final class InvalidFieldTypeOptions extends InvalidArgumentException
{
    public static function kind(string $key, string $expected): self
    {
        return new self(sprintf('The option "%s" is not %s.', $key, $expected));
    }
}
