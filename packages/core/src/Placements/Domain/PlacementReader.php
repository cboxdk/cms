<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;

/**
 * The reads of the placement commands' resolve phase (PRD 6.2 phase 1). They run in the command
 * transaction under the call's actor context. What the actor's regions do not reach reads as
 * absent (PRD 5.10): a placement below a node they do not reach, a node, and an entry the actor can
 * neither reach through its home nor see live somewhere. placementVersion() and placements() are
 * the reads past the regions: a placement's version, and every placement of an entry in a locale,
 * without slugs, because the canonical placement is one across all of them (invariant 14). Each read is a fixed number of statements, however
 * many placements a node or an entry has (GUARDRAILS 4.1). None takes a lock; the commit locks what
 * was read and checks its version.
 */
#[Internal]
interface PlacementReader
{
    /**
     * The placement's released stage with its locales, or null.
     */
    public function placement(PlacementId $placement): ?StoredPlacement;

    /**
     * The placement's version wherever it is, past the actor's regions, or null when no placement
     * has the id: a command on a placement the actor cannot reach is then unauthorized, not a
     * conflict.
     */
    public function placementVersion(PlacementId $placement): ?AggregateVersion;

    /**
     * The entry's version, or null.
     */
    public function entry(EntryId $entry): ?AggregateVersion;

    public function node(NodeId $node): ?StoredNode;

    public function site(SiteId $site): ?StoredSite;

    /**
     * Whether a placement that is not withdrawn has the slug below the node in the locale.
     */
    public function slugTaken(NodeId $node, Locale $locale, Slug $slug): bool;

    /**
     * Every placement of the entry in the locale, on every site, in the order of their ids.
     */
    public function placements(EntryId $entry, Locale $locale): LocalePlacements;
}
