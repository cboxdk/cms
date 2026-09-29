<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * An actor as the ActorDirectory reads it: an aggregate with its id, class, state, version and
 * credential generation (PRD 5.16). The version counts every change of the actor, starting at 1,
 * so a command that read the actor fails its version check when a deactivation committed in the
 * meantime (PRD 6.2 phase 7, invariant 37).
 */
#[Experimental]
final readonly class Actor
{
    public const int FIRST_VERSION = 1;

    public function __construct(
        public ActorId $id,
        public ActorClass $class,
        public ActorState $state,
        public int $version,
        public CredentialGeneration $credentialGeneration,
    ) {
        if ($version < self::FIRST_VERSION) {
            throw InvalidIdentity::version($version);
        }
    }

    public function isActive(): bool
    {
        return $this->state->isActive();
    }
}
