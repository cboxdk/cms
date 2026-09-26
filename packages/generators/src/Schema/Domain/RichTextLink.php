<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A kind of link that a `rich_text` field allows (Portable Text, PRD 11.10).
 */
#[Internal]
enum RichTextLink: string
{
    case Url = 'url';
}
