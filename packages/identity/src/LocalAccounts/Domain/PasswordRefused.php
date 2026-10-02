<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use RuntimeException;

/**
 * The password policy refused a password, for $reason. The message says which rule, never the
 * password or anything derived from it, so it can be shown and logged.
 */
#[Internal]
final class PasswordRefused extends RuntimeException
{
    private function __construct(public readonly PasswordErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(PasswordErrorCode $reason): self
    {
        return new self($reason, match ($reason) {
            PasswordErrorCode::TooShort => sprintf('The password has fewer than %d characters.', PasswordPolicy::MIN_CHARACTERS),
            PasswordErrorCode::TooLong => sprintf('The password is longer than %d bytes.', PasswordPolicy::MAX_BYTES),
            PasswordErrorCode::Breached => 'The password is known from data breaches.',
        });
    }

    public function code(): ErrorCode
    {
        return ErrorCode::from($this->reason->value);
    }
}
