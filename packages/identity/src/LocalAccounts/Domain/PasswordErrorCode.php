<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Why the password policy refused a password (PRD 5.16, "Lokale konti"). Each is a code of the
 * error catalog, Cbox\Cms\Contracts\Errors\ErrorCode.
 */
#[Internal]
enum PasswordErrorCode: string
{
    /** Fewer than PasswordPolicy::MIN_CHARACTERS characters. */
    case TooShort = 'password_too_short';

    /** More than PasswordPolicy::MAX_BYTES bytes in UTF-8. */
    case TooLong = 'password_too_long';

    /** Known from data breaches, as BreachedPasswords says. */
    case Breached = 'password_breached';
}
