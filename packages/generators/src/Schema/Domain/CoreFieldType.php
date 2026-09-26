<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The field types of the core in the first edition of the blueprint schema v1. A field type that an
 * addon contributes is an AddonFieldType instead.
 */
#[Internal]
enum CoreFieldType: string
{
    case Text = 'text';

    case LongText = 'long_text';

    case Integer = 'integer';

    case Decimal = 'decimal';

    case Boolean = 'boolean';

    case Date = 'date';

    case Datetime = 'datetime';

    case Select = 'select';

    case RichText = 'rich_text';

    case Group = 'group';
}
