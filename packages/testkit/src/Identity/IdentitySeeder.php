<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * Creates and changes actors and service credentials for tests (PRD 5.16).
 *
 * Login, sessions, personal tokens and the commands that issue credentials and change actors come
 * with the identity block; until then a test gets its actors and credentials from a seeder, and
 * nothing else writes them. The testkit's FakeIdentity seeds its own memory, and
 * PostgresIdentitySeeder writes the core's tables as the owner role.
 *
 * Every change counts the actor's version up. Deactivating or deprovisioning an actor also counts
 * its credential generation up, as the commands will (PRD 5.16, 6.4).
 */
#[Experimental]
interface IdentitySeeder
{
    /**
     * A new actor at version 1 and credential generation 1.
     */
    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor;

    /**
     * Issues a service credential and returns it as a transport carries it; it is shown only
     * here. The credential carries the actor's current generation.
     *
     * @throws InvalidIdentity when an actor is unknown or not active, the actor is not of the
     *                         class service, or the expiry is not after the clock's time
     */
    public function issue(ServiceCredentialSpec $spec): TransportCredential;

    /**
     * Moves the actor to the state. Deactivated and Deprovisioned also count its credential
     * generation up. A deprovisioned actor never changes again.
     *
     * @throws InvalidIdentity when the actor is unknown or deprovisioned
     */
    public function changeState(ActorId $id, ActorState $state): Actor;

    /**
     * Counts the actor's credential generation up, as actor.credentials_revoke does, so every
     * credential it holds is refused.
     *
     * @throws InvalidIdentity when the actor is unknown
     */
    public function revokeCredentials(ActorId $id): Actor;
}
