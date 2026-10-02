<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Activates a pending actor (PRD 5.16, 6.4), version 1 of actor.activate: the last step of a
 * registration, once the actor's credential is written. It takes the actor and the version of it
 * the caller read; an actor at another version is version_conflict (invariant 11). Only a pending
 * actor is activated: an actor in any other state is refused with validation_failed, so a
 * deactivated or deprovisioned actor never comes back through it. In one changeset the actor
 * becomes active at its next version, and actor.activated tells about it.
 */
#[CommandName('actor.activate', version: 1)]
#[Experimental]
final readonly class ActivateActor implements ExpectsVersions
{
    public function __construct(
        public ActorId $actor,
        public AggregateVersion $version,
    ) {}

    /**
     * The actor, at the version the caller read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->actor, $this->version));
    }
}
