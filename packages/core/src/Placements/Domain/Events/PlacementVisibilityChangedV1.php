<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;
use Override;

/**
 * Version 1 of the payload of placement.visibility_changed: the placement, its entry, the locale,
 * the state before and after, live_from and live_until (null when open or when there is no
 * window), and next_transition_at.
 */
#[Experimental]
final readonly class PlacementVisibilityChangedV1 implements EventPayload
{
    public function __construct(
        public PlacementId $placement,
        public EntryId $entry,
        public Locale $locale,
        public Visibility $previous,
        public Visibility $visibility,
        public ?DateTimeImmutable $liveFrom,
        public ?DateTimeImmutable $liveUntil,
        public ?DateTimeImmutable $nextTransitionAt,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('placement', EventDatum::identifier($this->placement))
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('locale', EventDatum::identifier($this->locale))
            ->with('previous', EventDatum::enum($this->previous))
            ->with('visibility', EventDatum::enum($this->visibility))
            ->with('live_from', $this->time($this->liveFrom))
            ->with('live_until', $this->time($this->liveUntil))
            ->with('next_transition_at', $this->time($this->nextTransitionAt));
    }

    private function time(?DateTimeImmutable $at): EventDatum
    {
        return $at instanceof DateTimeImmutable ? EventDatum::time($at) : EventDatum::null();
    }
}
