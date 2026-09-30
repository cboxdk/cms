<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;

/**
 * The placement with a slug below a node in a locale (PRD 5.9 step 3), in its released stage, as
 * the reader may read it, with what the precedence of PRD 6.6 needs: its stored visibility, its
 * window and whether it is canonical; the entry's type and lifecycle, null when the reader cannot
 * read the entry; and the release state of the variant's head, null when the reader cannot read the
 * head.
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
    ) {}
}
