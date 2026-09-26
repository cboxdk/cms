<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A block style that a `rich_text` field allows (Portable Text, PRD 11.10).
 */
#[Internal]
enum RichTextStyle: string
{
    case Normal = 'normal';

    case H2 = 'h2';

    case H3 = 'h3';

    case H4 = 'h4';

    case H5 = 'h5';

    case H6 = 'h6';

    case Blockquote = 'blockquote';
}
