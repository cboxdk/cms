<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\SessionToken;
use DateTimeImmutable;

/**
 * A session just issued (PRD 5.16): its id, which goes in the session cookie and is shown only
 * here; the session as the store keeps it; and when it ends unless a request renews it.
 */
#[Internal]
final readonly class NewSession
{
    public function __construct(
        public SessionToken $token,
        public StoredSession $session,
        public DateTimeImmutable $expiresAt,
    ) {}
}
