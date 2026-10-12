<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A placement of an entry as entry.get gives it (PRD 5.7): its id, the node its released
 * generation sits under, its version and its locales in the order of the locales.
 */
#[Experimental]
final readonly class EntryPlacement
{
    /**
     * @param  list<EntryPlacementLocale>  $locales
     */
    public function __construct(
        public PlacementId $id,
        public NodeId $node,
        public AggregateVersion $version,
        public array $locales,
    ) {}
}
