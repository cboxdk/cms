<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * Reads actors as aggregates (PRD 5.16, 6.2): the command kernel reads the actor and every actor in
 * its on-behalf-of chain in the resolve phase, rejects one that is not active, and checks their
 * versions again at commit (invariant 37).
 *
 * A directory reads the current state from the primary, never from a copy that can lag, so a
 * deactivation that committed is seen by the next read. It never writes: actors change only
 * through commands.
 */
#[Experimental]
interface ActorDirectory
{
    /**
     * The actor with the id, or null when no actor has it. An actor is never removed, so an id
     * that was found once is always found; a deprovisioned actor is found in that state.
     */
    public function find(ActorId $id): ?Actor;
}
