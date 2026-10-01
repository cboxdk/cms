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
use Cbox\Cms\Core\Placements\Domain\Dto\EntryRelease;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;

/**
 * The reads of the placement commands' resolve phase (PRD 6.2 phase 1). They run in the command
 * transaction under the call's actor context. What the actor's regions do not reach reads as
 * absent (PRD 5.10): a placement below a node they do not reach, a node, and an entry the actor can
 * neither reach through its home nor see live somewhere. placementVersion(), entryRelease(),
 * placements() and everyLocale() are the reads past the regions: a placement's version, and every placement of an
 * entry in a locale or in all of them, without slugs, because the canonical placement is one across
 * all of them (invariant 14) and unpublishing closes every one (PRD 6.4). Each read is a fixed number of statements, however
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

    /**
     * The entry's type, lifecycle state and the release state and version of its shared head,
     * past the actor's regions, or null when no entry has the id: what a command weighs before it
     * makes a placement of the entry live or scheduled (invariant 6), since the entry's home may
     * lie outside the regions that reach the placement's node.
     */
    public function entryRelease(EntryId $entry): ?EntryRelease;

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

    /**
     * Every placement of the entry in every locale it has placements in, on every site, one
     * LocalePlacements per locale in the order of the locales, each in the order of the ids: what
     * a command that publishes or unpublishes the entry's content weighs (PRD 6.4).
     *
     * @return list<LocalePlacements>
     */
    public function everyLocale(EntryId $entry): array;
}
