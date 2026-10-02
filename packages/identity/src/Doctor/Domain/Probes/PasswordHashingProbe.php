<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The password hashing of this PHP process.
 */
#[Internal]
interface PasswordHashingProbe
{
    /**
     * Whether password_hash() can make Argon2id hashes: PASSWORD_ARGON2ID is defined and
     * password_algos() lists it.
     */
    public function argon2id(): bool;
}
