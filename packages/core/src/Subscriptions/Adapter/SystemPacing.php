<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Override;

/**
 * Real time from the monotonic clock, hrtime(), which measures durations and deadlines of real
 * waits, and a real wait.
 */
#[Internal]
final readonly class SystemPacing implements Pacing
{
    #[Override]
    public function milliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    #[Override]
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1_000);
        }
    }
}
