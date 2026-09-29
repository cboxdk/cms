<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * CredentialVerifier::verify() refused the credential, for $reason. The message never holds the
 * credential.
 */
#[Experimental]
final class CredentialRejected extends RuntimeException
{
    private function __construct(public readonly CredentialErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(CredentialErrorCode $reason): self
    {
        return new self($reason, sprintf('The credential was refused: %s.', match ($reason) {
            CredentialErrorCode::Malformed => 'it is not in the form of a credential, or its checksum does not match',
            CredentialErrorCode::Unknown => 'no credential has it',
            CredentialErrorCode::Expired => 'it has expired',
            CredentialErrorCode::ActorNotActive => 'its actor, or an actor it acts on behalf of, is not active',
            CredentialErrorCode::Revoked => 'it was revoked when its actor\'s credential generation was counted up',
        }));
    }
}
