<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use RuntimeException;

/**
 * A LocalCredentialStore refused to bind a local account: the login identifier is another
 * account's, or the actor has a local account already (PRD 5.16, "Lokale konti": one list of
 * people, one account per login). Nothing was written. The message names the actor, never the
 * login identifier, which is personal data.
 */
#[Experimental]
final class LocalAccountExists extends RuntimeException
{
    public const string CODE = 'local_account_exists';

    public static function forLogin(ActorId $actor): self
    {
        return new self(sprintf('The login identifier of actor %s is the login of another local account already.', $actor->toString()));
    }

    public static function forActor(ActorId $actor): self
    {
        return new self(sprintf('Actor %s has a local account already.', $actor->toString()));
    }
}
