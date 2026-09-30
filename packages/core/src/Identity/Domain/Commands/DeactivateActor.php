<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Command;

/**
 * Deactivates an actor (PRD 5.16, 6.4), version 1 of actor.deactivate: the actor, and what
 * deactivated it, which is noted on the actor. In one changeset the actor becomes deactivated, its
 * version and credential generation rise, so every credential it holds is refused at once, and its
 * direct grants end, so it reaches nothing; actor.deactivated tells about it. From the commit on, a
 * command as the actor or on its behalf is rejected, and one it was running fails with
 * version_conflict (invariant 37). An actor that is active or pending can be deactivated; for one
 * that is deactivated or deprovisioned already the command changes nothing and is rejected.
 */
#[CommandName('actor.deactivate', version: 1)]
#[Experimental]
final readonly class DeactivateActor implements Command
{
    public function __construct(
        public ActorId $actor,
        public DeactivationSource $source = DeactivationSource::Local,
    ) {}
}
