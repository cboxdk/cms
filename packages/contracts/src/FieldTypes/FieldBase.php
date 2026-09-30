<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The core field types an addon's field type can take the form of (FieldShape): the value is
 * stored, typed, validated, encoded and queried as a value of that core type. Each case's value is
 * the core field type's name in blueprint v1. A group and rich text are not bases, because their
 * form is their nested fields and their blocks, which a blueprint declares, not an addon.
 */
#[Experimental]
enum FieldBase: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
    case Select = 'select';
}
