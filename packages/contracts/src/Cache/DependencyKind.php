<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The kinds of content a dependency key names (PRD 9.4). The value is the key's prefix: `e-` for
 * an entry and `n-` for a node. PRD 9.4 also lists `a-` for an asset, `q-` for a query descriptor
 * and `c-` for a page's composition; they come with assets, query descriptors and curation, which
 * are later blocks.
 */
#[Experimental]
enum DependencyKind: string
{
    case Entry = 'e';
    case Node = 'n';
}
