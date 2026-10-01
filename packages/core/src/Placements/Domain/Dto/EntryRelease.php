<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;

/**
 * An entry as a placement command reads it before it makes a placement live or scheduled (PRD
 * 5.7, invariant 6), past the actor's regions: its type, its lifecycle state, and the release state
 * and version of the head of its shared variant, both null when it has no shared head.
 */
#[Internal]
final readonly class EntryRelease
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public EntryLifecycle $lifecycle,
        public ?ReleaseState $release,
        public ?AggregateVersion $version,
    ) {}

    /**
     * Whether a placement of the entry may be shown, with the rungs of PRD 6.6 above the
     * placement's own: the entry is active, its shared head exists and is not withdrawn, and, for a
     * type with stages, it has a released revision. A type with stages none is public as soon as it
     * is saved.
     */
    public function showable(Stages $stages): bool
    {
        return $this->lifecycle === EntryLifecycle::Active
            && $this->release instanceof ReleaseState
            && $this->release !== ReleaseState::Withdrawn
            && ($stages === Stages::None || $this->release === ReleaseState::Released);
    }

    /**
     * The aggregate's version as the commit checks it: the head's while the entry is active.
     */
    public function aggregateVersion(): ?AggregateVersion
    {
        return $this->lifecycle === EntryLifecycle::Active ? $this->version : null;
    }
}
