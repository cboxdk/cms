<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The changes a seeder makes to an actor, the same for every seeder: each counts the version up,
 * and deactivation, deprovisioning and a revocation count the credential generation up (PRD 5.16,
 * 6.4).
 */
#[Experimental]
final readonly class ActorChanges
{
    /**
     * @throws InvalidIdentity when the actor is deprovisioned
     */
    public static function state(Actor $actor, ActorState $state): Actor
    {
        if ($actor->state === ActorState::Deprovisioned) {
            throw InvalidIdentity::deprovisioned($actor->id);
        }

        return new Actor(
            $actor->id,
            $actor->class,
            $state,
            $actor->version + 1,
            $state->revokesCredentials() ? $actor->credentialGeneration->next() : $actor->credentialGeneration,
        );
    }

    public static function revoke(Actor $actor): Actor
    {
        return new Actor(
            $actor->id,
            $actor->class,
            $actor->state,
            $actor->version + 1,
            $actor->credentialGeneration->next(),
        );
    }
}
