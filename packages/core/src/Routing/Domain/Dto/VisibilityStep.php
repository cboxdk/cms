<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use DateTimeImmutable;

/**
 * The visibility decision (PRD 6.6, 6.7): the rung that decided, at the Clock's time, from what it
 * was decided on: the entry's lifecycle and the head's release state (null when the reader cannot
 * read them), the placement's stored visibility and its window. validUntil is when the decision can
 * change next because of the window: its end for a visible placement, its start for one whose
 * window has not begun, and null when the window never changes it again.
 */
#[Experimental]
final readonly class VisibilityStep
{
    public function __construct(
        public VisibilityDecision $decision,
        public DateTimeImmutable $at,
        public ?EntryLifecycle $lifecycle,
        public ?ReleaseState $release,
        public Visibility $stored,
        public ?TimeWindow $window,
        public ?DateTimeImmutable $validUntil,
    ) {}
}
