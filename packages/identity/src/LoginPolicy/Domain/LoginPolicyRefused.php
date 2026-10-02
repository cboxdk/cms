<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;

/**
 * The login policy refused a login, for $reason (PRD 5.16). No session may be issued. The message
 * names the rule only, never the actor, a login identifier or a credential, so it can be logged;
 * the person is only told that the login failed.
 */
#[Internal]
final class LoginPolicyRefused extends RuntimeException
{
    private function __construct(public readonly LoginPolicyErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(LoginPolicyErrorCode $reason): self
    {
        return new self($reason, sprintf('The login policy refused the login: %s.', match ($reason) {
            LoginPolicyErrorCode::ActorNotActive => 'the actor is not active',
            LoginPolicyErrorCode::ClassNotAllowed => 'a service actor never logs in',
            LoginPolicyErrorCode::ConnectionNotAllowed => 'the policy of the actor\'s class does not list the connection',
            LoginPolicyErrorCode::MethodNotAllowed => 'the policy does not allow the method on the connection',
            LoginPolicyErrorCode::LocalDisabled => 'local login is switched off for the actor\'s class',
            LoginPolicyErrorCode::AuthoritativeLink => 'the actor is linked to an authoritative connection and has no local login',
            LoginPolicyErrorCode::FactorsUnavailable => 'the login did not give the factors the policy requires',
        }));
    }
}
