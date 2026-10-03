<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\TypedLogin;
use Cbox\Cms\Identity\Login\Domain\InvalidClientAddress;
use SensitiveParameter;

/**
 * Reads what a login or password reset form posted into the domain's values (PRD 5.16, GUARDRAILS
 * 2.2), so no raw string reaches an action: the email field into a TypedLogin, the password field
 * into a Password or null when it was left empty or is not text, and the client's IP address into
 * a ClientAddress or null when the request has none or it is not an IP address.
 */
#[Internal]
final readonly class LoginInput
{
    public static function login(#[SensitiveParameter] mixed $input): TypedLogin
    {
        if (! is_string($input) || trim($input) === '') {
            return TypedLogin::missing();
        }

        $identifier = LoginIdentifier::typed($input);

        return $identifier instanceof LoginIdentifier ? TypedLogin::of($identifier) : TypedLogin::unreadable();
    }

    public static function password(#[SensitiveParameter] mixed $input): ?Password
    {
        return is_string($input) && $input !== '' ? new Password($input) : null;
    }

    public static function address(#[SensitiveParameter] mixed $input): ?ClientAddress
    {
        if (! is_string($input)) {
            return null;
        }

        try {
            return new ClientAddress($input);
        } catch (InvalidClientAddress) {
            return null;
        }
    }
}
