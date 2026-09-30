<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The format of a TextShape, as `format` of a core `text` field: any text, an email address or a
 * URL.
 */
#[Experimental]
enum TextFormat: string
{
    case Plain = 'plain';
    case Email = 'email';
    case Url = 'url';
}
