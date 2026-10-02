<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A staff actor as actor.list gives it (PRD 5.16): its id, state and version, and its profile, or
 * null when the reader may not read it.
 */
#[Experimental]
final readonly class ListedActor
{
    public function __construct(
        public ActorId $id,
        public ActorState $state,
        public AggregateVersion $version,
        public ?ListedProfile $profile,
    ) {}

    /**
     * This actor as a reader with $access may see it.
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return new self($this->id, $this->state, $this->version, $this->profile?->visibleTo($access));
    }
}
