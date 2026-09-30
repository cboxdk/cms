<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A placement as a placement command reads it (PRD 5.7): the entry it places, the node of its
 * released generation, its version and its locales.
 */
#[Internal]
final readonly class StoredPlacement
{
    /**
     * @param  list<StoredPlacementLocale>  $locales
     */
    public function __construct(
        public PlacementId $id,
        public EntryId $entry,
        public NodeId $node,
        public AggregateVersion $version,
        public array $locales,
    ) {}

    public function locale(Locale $locale): ?StoredPlacementLocale
    {
        foreach ($this->locales as $stored) {
            if ($stored->locale->equals($locale)) {
                return $stored;
            }
        }

        return null;
    }
}
