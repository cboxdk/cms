<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * What the shared suite SessionCredentialContract runs against: an IdentityHarness whose verifier
 * also holds sessions, as the verifier the identity module decorates does (PRD 5.16), and that can
 * start one for a person who logged in.
 */
#[Experimental]
interface SessionIdentityHarness extends IdentityHarness
{
    /**
     * Starts a session of the actor, as a login the policy allowed would, and returns its id in
     * the session form, as the session cookie carries it; it is shown only here. The session
     * carries the actor's current credential generation.
     *
     * @throws InvalidIdentity when the actor is unknown or not active, or of the class service,
     *                         which never logs in
     */
    public function startSession(ActorId $actor): TransportCredential;
}
