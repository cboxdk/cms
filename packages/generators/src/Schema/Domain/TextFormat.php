<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a `text` field holds: plain text, an email address or a URL.
 */
#[Internal]
enum TextFormat: string
{
    case Plain = 'plain';

    case Email = 'email';

    case Url = 'url';
}
