<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * Step 3 of a resolution (PRD 5.9): the slug looked up below the node, the mount's source for a
 * mount. The placement found, or null when the reader can read none; its entry; the entry's type,
 * null when the reader cannot read the entry; whether the placement is the canonical one of its
 * entry in the locale; and whether the type has URLs at all (its blueprint's routable).
 */
#[Experimental]
final readonly class PlacementStep
{
    public function __construct(
        public NodeId $lookedUnder,
        public Slug $slug,
        public ?PlacementId $placement = null,
        public ?EntryId $entry = null,
        public ?TypeId $type = null,
        public bool $canonical = false,
        public bool $routable = false,
    ) {}
}
