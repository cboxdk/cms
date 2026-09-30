<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;

/**
 * One placement of an entry in one locale, as the canonical rule weighs it (PRD 5.7, invariant 14):
 * the placement's version, its visibility state, its window and whether it is canonical. It holds
 * no slug and no presentation, because the kernel reads it for every placement of the entry,
 * including those below nodes the actor's regions do not reach.
 */
#[Internal]
final readonly class PlacementState
{
    public function __construct(
        public PlacementId $placement,
        public AggregateVersion $version,
        public Visibility $visibility,
        public ?TimeWindow $window,
        public bool $canonical,
    ) {}

    /**
     * Whether it counts for the canonical flag: every placement but a withdrawn one.
     */
    public function candidate(): bool
    {
        return $this->visibility !== Visibility::Withdrawn;
    }

    public function visibleAt(DateTimeImmutable $at): bool
    {
        return $this->visibility->visibleAt($this->window, $at);
    }

    /**
     * The same placement with another visibility state and window.
     */
    public function withWindow(Visibility $visibility, ?TimeWindow $window): self
    {
        return new self($this->placement, $this->version, $visibility, $window, $this->canonical);
    }
}
