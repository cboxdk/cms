<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use LogicException;

/**
 * How a password reset ended (PRD 5.16), one of three:
 *
 * - loggedIn(): the password was set and the login policy let the reset log the person in, with
 *   the new session;
 * - changed(): the password was set, but the login policy does not let a reset log the person in,
 *   such as when local staff logins need a passkey; the person signs in on the login page;
 * - refused(): nothing was set, with the catalog code and whether it is about the password field:
 *   password_reset_token_invalid for a token that is unknown, used or expired, whatever the
 *   reason; validation_required, password_too_short, password_too_long and password_breached for
 *   the password; breached_passwords_unavailable when the breach check could not be made.
 */
#[Internal]
final readonly class PasswordResetOutcome
{
    /**
     * The codes a refusal about the password field has.
     *
     * @var list<ErrorCode>
     */
    private const array PASSWORD_CODES = [
        ErrorCode::ValidationRequired,
        ErrorCode::PasswordTooShort,
        ErrorCode::PasswordTooLong,
        ErrorCode::PasswordBreached,
    ];

    private function __construct(
        public ?NewSession $session,
        public bool $passwordSet,
        public ?ErrorCode $refusal,
    ) {}

    public static function loggedIn(NewSession $session): self
    {
        return new self($session, true, null);
    }

    public static function changed(): self
    {
        return new self(null, true, null);
    }

    /**
     * @throws LogicException when the code is not one a reset is refused with
     */
    public static function refused(ErrorCode $code): self
    {
        if (! in_array($code, [...self::PASSWORD_CODES, ErrorCode::PasswordResetTokenInvalid, ErrorCode::BreachedPasswordsUnavailable], true)) {
            throw new LogicException(sprintf('A password reset is not refused with %s.', $code->value));
        }

        return new self(null, false, $code);
    }

    /**
     * Whether the refusal is about the password the person typed, not the link.
     */
    public function aboutPassword(): bool
    {
        return in_array($this->refusal, self::PASSWORD_CODES, true);
    }
}
