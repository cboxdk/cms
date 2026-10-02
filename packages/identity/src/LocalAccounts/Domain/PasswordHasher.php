<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordHash;

/**
 * Hashes and verifies the passwords of the local accounts (PRD 5.16, "Lokale konti"), with Argon2id
 * at the installation's parameters, cbox-cms.identity.passwords.argon2id.
 *
 * - hash() makes the hash of a password with a new random salt.
 * - verify() tells whether the password is the one the hash was made of, at the cost of the
 *   hash's own parameters.
 * - needsRehash() tells whether the hash was made with other parameters than the installation's,
 *   so the login that verified it hashes the password again.
 * - dummy() is a fixed hash at the installation's parameters that no password verifies against.
 *   A login of an unknown identifier verifies against it, so it does the same hashing work as a
 *   wrong password and its time does not tell which identifiers have an account.
 */
#[Internal]
interface PasswordHasher
{
    public function hash(Password $password): PasswordHash;

    public function verify(Password $password, PasswordHash $hash): bool;

    public function needsRehash(PasswordHash $hash): bool;

    public function dummy(): PasswordHash;
}
