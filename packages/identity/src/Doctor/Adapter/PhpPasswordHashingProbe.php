<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Override;

/**
 * Asks this PHP process for its password hashing algorithms.
 */
#[Internal]
final readonly class PhpPasswordHashingProbe implements PasswordHashingProbe
{
    #[Override]
    public function argon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true);
    }
}
