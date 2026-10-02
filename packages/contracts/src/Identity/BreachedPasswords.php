<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Whether a password is known from data breaches (PRD 5.16 "Lokale konti": a local account refuses
 * a password that is known to be leaked).
 *
 * isBreached() answers true when the password is known from a breach and false when it is not.
 * When it cannot tell, because the service it asks does not answer or answers something it cannot
 * read, it throws BreachedPasswordsUnavailable, a retryable error: it fails closed and never
 * answers false for a password it could not check. An implementation never stores, logs or sends
 * the password itself; one that asks a service sends at most what cannot identify the password,
 * such as the first five hex digits of its SHA-1 hash (k-anonymity).
 *
 * The default is the identity module's HibpBreachedPasswords, on the Have I Been Pwned range API.
 */
#[Experimental]
interface BreachedPasswords
{
    /**
     * @throws BreachedPasswordsUnavailable when the check cannot be made
     */
    public function isBreached(Password $password): bool;
}
