<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The core field type a field's value takes the form of (PRD 11.12, 13.3): the field type itself
 * for a core field type, and the base of its shape for an addon's. A form picks its input by it
 * and may refine the input by the field's type. The cases are the eleven core field types of the
 * blueprint schema v1.
 */
#[Experimental]
enum EditorBase: string
{
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
    case Decimal = 'decimal';
    case Group = 'group';
    case Integer = 'integer';
    case LongText = 'long_text';
    case Relation = 'relation';
    case RichText = 'rich_text';
    case Select = 'select';
    case Text = 'text';
}
