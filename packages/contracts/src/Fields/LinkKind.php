<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a link field's value points at (B2-T76): an entry, with an optional locale; a node of the
 * structure; or an absolute http or https URL. A blueprint's `kinds` limits the kinds a link field
 * allows.
 */
#[Experimental]
enum LinkKind: string
{
    case Entry = 'entry';
    case Node = 'node';
    case Url = 'url';
}
