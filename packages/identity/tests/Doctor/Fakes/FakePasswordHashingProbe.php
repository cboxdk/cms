<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Doctor\Fakes;

use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Override;

/**
 * A PHP with Argon2id until the test says otherwise.
 */
final class FakePasswordHashingProbe implements PasswordHashingProbe
{
    public function __construct(public bool $argon2id = true) {}

    #[Override]
    public function argon2id(): bool
    {
        return $this->argon2id;
    }
}
