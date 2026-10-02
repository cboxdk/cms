<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * A reset token as FakeLocalCredentialStore holds it, under its SHA-256: the actor, when it
 * expires and when it was used, or null.
 */
#[Internal]
final readonly class FakeResetToken
{
    public function __construct(
        public ActorId $actor,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt = null,
    ) {}

    /**
     * The token used at the time.
     */
    public function usedAt(DateTimeImmutable $time): self
    {
        return new self($this->actor, $this->expiresAt, $time);
    }
}
