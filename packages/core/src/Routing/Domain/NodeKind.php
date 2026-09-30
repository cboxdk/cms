<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The kind of a node (PRD 5.8): a site root, a section, a page, a list, a storage folder or a mount,
 * which shows another node's placements.
 */
#[Experimental]
enum NodeKind: string
{
    case Site = 'site';
    case Section = 'section';
    case Page = 'page';
    case List = 'list';
    case Storage = 'storage';
    case Mount = 'mount';
}
