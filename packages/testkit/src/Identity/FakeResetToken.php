<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * A reset token as FakeLocalCredentialStore holds it, under its SHA-256: the actor, when it
 * expires and whether it was used.
 */
#[Internal]
final readonly class FakeResetToken
{
    public function __construct(
        public ActorId $actor,
        public DateTimeImmutable $expiresAt,
        public bool $used = false,
    ) {}
}
