<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * A password the local connection verified (PRD 5.16): the assertion it gives, the actor of the
 * account, and the hash the account held once the login was through with it, the one verified, or
 * the one a rehash replaced it with. LocalConnection::stillCurrent() compares it with the account
 * as it is later, so a login can tell that the password changed after it was checked.
 */
#[Internal]
final readonly class LocalLogin
{
    public function __construct(
        public VerifiedAssertion $assertion,
        public ActorId $actor,
        public PasswordHash $hash,
    ) {}
}
