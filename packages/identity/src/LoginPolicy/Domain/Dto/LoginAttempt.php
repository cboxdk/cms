<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Domain\IdpSessionId;

/**
 * A login a connection verified and a login path asks the policy about (PRD 5.16): the actor the
 * path found for the IdP identity, the method of the path, the assertion the connection gave,
 * whose connection, amr and acr the policy judges, and the identity provider's session id, the
 * `sid` of the ID token, or null for a login without one, such as a local login. The session
 * stores the IdP session id, so a back-channel logout that names it ends the session.
 */
#[Internal]
final readonly class LoginAttempt
{
    public function __construct(
        public ActorId $actor,
        public LoginMethod $method,
        public VerifiedAssertion $assertion,
        public ?IdpSessionId $idpSession = null,
    ) {}
}
