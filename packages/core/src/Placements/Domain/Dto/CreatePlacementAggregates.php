<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use DateTimeImmutable;
use Override;

/**
 * What placement.create read (PRD 6.2 phase 1), at the time it read: the placement, which the
 * command expects not to exist; the entry, the node and the site, each null when it does not exist
 * or the actor's regions do not reach it; whether each slug is taken below the node; and every
 * placement of the entry in each locale, for the canonical rule. The kernel checks at commit that
 * all of it but the entry is still at the version read, so a slug given away or a canonical
 * placement made meanwhile is version_conflict. The entry is read only to know the actor may place
 * it: a placement changes nothing of the entry, its foreign key keeps the entry there, and an actor
 * who places an entry that is live elsewhere reads it without reaching its home, where a row lock
 * on it would find nothing (PRD 5.10).
 */
#[Internal]
final readonly class CreatePlacementAggregates implements Aggregates
{
    /**
     * @param  list<SlugClaim>  $slugs  in the order of the command's slugs
     * @param  list<LocalePlacements>  $placements  one per locale of the command's slugs
     */
    public function __construct(
        public PlacementId $placement,
        public ?AggregateVersion $existing,
        public EntryId $entry,
        public ?AggregateVersion $entryVersion,
        public NodeId $node,
        public ?StoredNode $storedNode,
        public SiteId $site,
        public ?StoredSite $storedSite,
        public array $slugs,
        public array $placements,
        public DateTimeImmutable $at,
    ) {}

    public function placementsIn(Locale $locale): ?LocalePlacements
    {
        foreach ($this->placements as $placements) {
            if ($placements->locale->equals($locale)) {
                return $placements;
            }
        }

        return null;
    }

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [
            new ReadVersion($this->placement, $this->existing),
            new ReadVersion($this->node, $this->storedNode?->version),
            new ReadVersion($this->site, $this->storedSite?->version),
        ];

        foreach ($this->slugs as $claim) {
            $reads[] = $claim->read();
        }

        foreach ($this->placements as $placements) {
            array_push($reads, ...PlacementReads::of($placements));
        }

        return PlacementReads::unique($reads);
    }
}
