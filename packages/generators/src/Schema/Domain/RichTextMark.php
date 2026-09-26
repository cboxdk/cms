<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A decorator mark that a `rich_text` field allows (Portable Text, PRD 11.10).
 */
#[Internal]
enum RichTextMark: string
{
    case Strong = 'strong';

    case Em = 'em';

    case Underline = 'underline';

    case Strike = 'strike';

    case Code = 'code';

    case Sub = 'sub';

    case Sup = 'sup';
}
