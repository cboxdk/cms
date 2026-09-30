<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Override;

/**
 * Places an entry below a node of a site (PRD 5.7), version 1 of placement.create: the placement's
 * id, which the caller makes, the entry, the node, the site the node belongs to, and a slug in each
 * locale the placement is to have, each a locale the site publishes in.
 *
 * The placement is hidden in every locale until its window is set (placement.set_window). It is
 * decided on the node, not on the entry's home (PRD 5.10): the actor's grants must reach the node,
 * and the entry must be one the actor can read. A slug another placement that is not withdrawn has
 * below the node in the locale is refused with placement_slug_taken (invariant 15). The kernel
 * decides which placement of the entry is canonical in each locale (invariant 14). The command
 * expects the placement not to exist, so a create of an id that exists is version_conflict.
 */
#[CommandName('placement.create', version: 1)]
#[Experimental]
final readonly class CreatePlacement implements ExpectsVersions
{
    /**
     * @param  list<LocaleSlug>  $slugs
     */
    public function __construct(
        public PlacementId $placement,
        public EntryId $entry,
        public NodeId $node,
        public SiteId $site,
        public array $slugs,
    ) {}

    /**
     * The placement, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->placement));
    }
}
