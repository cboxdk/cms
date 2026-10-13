<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Gives a placement another slug in one locale (PRD 5.9), version 1 of placement.set_slug: the
 * placement, the version of it the caller saw, the locale and the new slug. The slug the placement
 * had goes to the slug history, pointing at the placement, so the URL that was out there answers
 * 301 to the placement's current URL in one hop, also after several renames.
 *
 * A rename is not a visibility change: the placement keeps its state, its window and its canonical
 * flag, and a visible placement stays visible at its new URL. The command is decided on the
 * placement's node (PRD 5.10). A slug another placement that is not withdrawn has below the node
 * in the locale is placement_slug_taken (invariant 15), and two commands that claim one slug
 * commit one after the other, the second as version_conflict. A locale the placement does not
 * have, a withdrawn placement and the slug the placement has already are validation_failed.
 */
#[CommandName('placement.set_slug', version: 1)]
#[Experimental]
final readonly class SetPlacementSlug implements ExpectsVersions
{
    public function __construct(
        public PlacementId $placement,
        public AggregateVersion $version,
        public Locale $locale,
        public Slug $slug,
    ) {}

    /**
     * The placement, at the version the caller saw. The slug is an aggregate the action reads too,
     * so the commit locks it, but the caller gives no version for it: a slug that is taken already
     * is placement_slug_taken, which says what to do about it.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->placement, $this->version));
    }
}
