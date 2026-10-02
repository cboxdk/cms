<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;

/**
 * The rules a local account's password must keep when it is set (PRD 5.16, "Lokale konti"), at
 * registration, at a change and at a reset:
 *
 * 1. at least MIN_CHARACTERS characters, counted as Unicode code points (password_too_short);
 * 2. at most MAX_BYTES bytes in UTF-8, so a long password cannot make hashing slow the server down
 *    (password_too_long);
 * 3. not known from data breaches, as BreachedPasswords says (password_breached). It is asked only
 *    for a password that keeps the first two rules, and when it cannot tell, its
 *    BreachedPasswordsUnavailable goes to the caller, so the password is neither set nor refused.
 *
 * There is no rule on the kinds of characters: length is what makes a password hard to guess.
 */
#[Internal]
final readonly class PasswordPolicy
{
    public const int MIN_CHARACTERS = 12;

    public const int MAX_BYTES = 1024;

    public function __construct(private BreachedPasswords $breached) {}

    /**
     * @throws PasswordRefused when the password breaks a rule
     * @throws BreachedPasswordsUnavailable when whether it is breached cannot be checked
     */
    public function check(Password $password): void
    {
        $value = $password->reveal();

        if (strlen($value) > self::MAX_BYTES) {
            throw PasswordRefused::because(PasswordErrorCode::TooLong);
        }

        if (mb_strlen($value, 'UTF-8') < self::MIN_CHARACTERS) {
            throw PasswordRefused::because(PasswordErrorCode::TooShort);
        }

        if ($this->breached->isBreached($password)) {
            throw PasswordRefused::because(PasswordErrorCode::Breached);
        }
    }
}
