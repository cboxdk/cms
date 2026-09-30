<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements\Fakes;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Override;

/**
 * The placement commands' reads from memory (GUARDRAILS 9), held to PostgresPlacementReader by
 * PlacementReaderBehaviour. A node is reached unless unreached() says otherwise, and a node, or a
 * placement below it, that the actor does not reach reads as absent, as row level security has it;
 * placements() and slugTaken() see every placement, as the Postgres reader's owner function and
 * the unique rule do.
 */
final class FakePlacementReader implements PlacementReader
{
    /** @var array<string, AggregateVersion> */
    private array $entries = [];

    /** @var array<string, StoredNode> */
    private array $nodes = [];

    /** @var array<string, true> */
    private array $unreached = [];

    /** @var array<string, StoredSite> */
    private array $sites = [];

    /** @var array<string, StoredPlacement> */
    private array $placements = [];

    public function withEntry(EntryId $entry, AggregateVersion $version): self
    {
        $this->entries[$entry->toString()] = $version;

        return $this;
    }

    public function withNode(StoredNode $node): self
    {
        $this->nodes[$node->id->toString()] = $node;

        return $this;
    }

    public function withSite(StoredSite $site): self
    {
        $this->sites[$site->id->toString()] = $site;

        return $this;
    }

    public function withPlacement(StoredPlacement $placement): self
    {
        $this->placements[$placement->id->toString()] = $placement;

        return $this;
    }

    /**
     * The actor's regions do not reach the node.
     */
    public function unreached(NodeId $node): self
    {
        $this->unreached[$node->toString()] = true;

        return $this;
    }

    #[Override]
    public function placement(PlacementId $placement): ?StoredPlacement
    {
        $stored = $this->placements[$placement->toString()] ?? null;

        return $stored instanceof StoredPlacement && ! isset($this->unreached[$stored->node->toString()]) ? $stored : null;
    }

    #[Override]
    public function placementVersion(PlacementId $placement): ?AggregateVersion
    {
        return ($this->placements[$placement->toString()] ?? null)?->version;
    }

    #[Override]
    public function entry(EntryId $entry): ?AggregateVersion
    {
        return $this->entries[$entry->toString()] ?? null;
    }

    #[Override]
    public function node(NodeId $node): ?StoredNode
    {
        return isset($this->unreached[$node->toString()]) ? null : ($this->nodes[$node->toString()] ?? null);
    }

    #[Override]
    public function site(SiteId $site): ?StoredSite
    {
        return $this->sites[$site->toString()] ?? null;
    }

    #[Override]
    public function slugTaken(NodeId $node, Locale $locale, Slug $slug): bool
    {
        foreach ($this->placements as $placement) {
            $stored = $placement->locale($locale);

            if ($placement->node->equals($node) && $stored !== null && $stored->slug->equals($slug) && $stored->visibility !== Visibility::Withdrawn) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function placements(EntryId $entry, Locale $locale): LocalePlacements
    {
        $states = [];

        foreach ($this->placements as $placement) {
            $stored = $placement->locale($locale);

            if ($placement->entry->equals($entry) && $stored !== null) {
                $states[$placement->id->toString()] = new PlacementState($placement->id, $placement->version, $stored->visibility, $stored->window, $stored->canonical);
            }
        }

        ksort($states);

        return new LocalePlacements($entry, $locale, array_values($states));
    }
}
