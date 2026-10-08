<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The lifecycle state of a node (PRD 5.8, 6.4): active, the state a node is created in, or
 * archived, read-only structure that takes no child, no route and no new placement, and whose
 * content keeps the visibility it has. Only node.archive moves a node, and only while no placement
 * below it is visible now or later.
 */
#[Experimental]
enum NodeLifecycle: string
{
    case Active = 'active';

    case Archived = 'archived';
}
