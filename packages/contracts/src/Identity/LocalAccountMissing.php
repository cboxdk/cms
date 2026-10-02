<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use RuntimeException;

/**
 * A LocalCredentialStore was asked to change the password of an actor, or issue a reset token for
 * it, and the actor has no local account (PRD 5.16). Nothing was written.
 */
#[Experimental]
final class LocalAccountMissing extends RuntimeException
{
    public const string CODE = 'local_account_missing';

    public static function of(ActorId $actor): self
    {
        return new self(sprintf('Actor %s has no local account.', $actor->toString()));
    }
}
