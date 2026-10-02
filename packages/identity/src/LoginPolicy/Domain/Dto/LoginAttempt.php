<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;

/**
 * A login a connection verified and a login path asks the policy about (PRD 5.16): the actor the
 * path found for the IdP identity, the method of the path, and the assertion the connection gave,
 * whose connection, amr and acr the policy judges.
 */
#[Internal]
final readonly class LoginAttempt
{
    public function __construct(
        public ActorId $actor,
        public LoginMethod $method,
        public VerifiedAssertion $assertion,
    ) {}
}
