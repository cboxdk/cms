<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * Where and as whom a seed run writes: the service actor, its access context, the seedable types of
 * the catalog, the nodes it reaches and the locales of the sites it reaches, which the languages
 * of an entry of a type with localization variants are seeded in.
 */
#[Internal]
final readonly class SeedScope
{
    /**
     * @param  non-empty-list<NodeId>  $nodes
     * @param  list<Locale>  $locales
     */
    public function __construct(
        public ActorId $actor,
        public AccessContext $access,
        public SeedCatalog $catalog,
        public array $nodes,
        public array $locales = [],
    ) {}
}
