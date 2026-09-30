<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * Where and as whom a seed run writes: the service actor, its access context, the seedable types of
 * the catalog and the nodes it reaches.
 */
#[Internal]
final readonly class SeedScope
{
    /**
     * @param  non-empty-list<NodeId>  $nodes
     */
    public function __construct(
        public ActorId $actor,
        public AccessContext $access,
        public SeedCatalog $catalog,
        public array $nodes,
    ) {}
}
