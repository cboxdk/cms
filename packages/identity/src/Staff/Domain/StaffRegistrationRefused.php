<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordRefused;
use RuntimeException;

/**
 * A registration of a local staff member stopped, with the catalog code of why (PRD 5.16).
 * $pending is the actor when the registration stopped after actor.register, which left it pending
 * with no active login; null when it stopped before anything was written. The message never holds
 * the email address or the password.
 */
#[Internal]
final class StaffRegistrationRefused extends RuntimeException
{
    private function __construct(public readonly ErrorCode $reason, string $message, public readonly ?ActorId $pending = null)
    {
        parent::__construct($message);
    }

    public static function loginTaken(?ActorId $pending = null): self
    {
        return new self(
            ErrorCode::LocalAccountExists,
            'The email address is the login of a local account already, so no other account can have it.'.self::left($pending),
            $pending,
        );
    }

    public static function password(PasswordRefused $refused): self
    {
        return new self($refused->code(), $refused->getMessage().' Nothing was written.');
    }

    public static function rejected(CatalogError $error, ?ActorId $pending): self
    {
        return new self($error->code, $error->message.self::left($pending), $pending);
    }

    private static function left(?ActorId $pending): string
    {
        return $pending instanceof ActorId
            ? sprintf(' Actor %s stays pending, without an active login.', $pending->toString())
            : ' Nothing was written.';
    }
}
