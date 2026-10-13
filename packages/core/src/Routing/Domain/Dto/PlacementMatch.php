<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;

/**
 * The placement with a slug below a node in a locale (PRD 5.9 step 3), in its released stage, as
 * the reader may read it, with what the precedence of PRD 6.6 needs: its stored visibility, its
 * window and whether it is canonical; the entry's type and lifecycle, null when the reader cannot
 * read the entry; the release state of the variant's head, null when the reader cannot read the
 * head; and whether the site hides the entry on the mount the slug was looked up through (PRD
 * 5.8), false when no mount was; and, when the slug looked up is one the placement had before it
 * was renamed (the slug history of PRD 5.9), the slug the placement has now, null when the slug is
 * the placement's own.
 */
#[Internal]
final readonly class PlacementMatch
{
    public function __construct(
        public PlacementId $placement,
        public EntryId $entry,
        public Visibility $visibility,
        public ?TimeWindow $window,
        public bool $canonical,
        public ?TypeId $type,
        public ?EntryLifecycle $lifecycle,
        public ?ReleaseState $release,
        public bool $hiddenOnMount = false,
        public ?Slug $renamedTo = null,
    ) {}
}
