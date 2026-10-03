<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A kind of JSON value, as a JSON Schema's `type` names it. An integer is also a number.
 */
#[Experimental]
enum JsonKind: string
{
    case Object = 'object';
    case Array = 'array';
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Null = 'null';

    /**
     * Whether a value of this kind is also a value of the other: the same kind, or an integer as a
     * number.
     */
    public function fits(self $other): bool
    {
        return $this === $other || ($this === self::Integer && $other === self::Number);
    }
}
