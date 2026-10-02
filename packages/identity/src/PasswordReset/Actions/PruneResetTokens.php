<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use DateInterval;

/**
 * Removes the password reset tokens that were used or expired more than WINDOW_HOURS hours before
 * the Clock's time (PRD 5.16), the work of cms:identity:prune, which the maintenance process
 * schedules every hour. A token that is still usable, or that was used or expired within the window,
 * stays. Returns how many it removed.
 */
#[Internal]
final readonly class PruneResetTokens
{
    public const int WINDOW_HOURS = 24;

    public function __construct(
        private LocalCredentialStore $store,
        private Clock $clock,
    ) {}

    public function prune(): int
    {
        return $this->store->pruneResetTokens($this->clock->now()->sub(new DateInterval(sprintf('PT%dH', self::WINDOW_HOURS))));
    }
}
