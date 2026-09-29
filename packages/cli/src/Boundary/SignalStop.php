<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Subscriptions\Domain\RunnerStop;
use Override;

/**
 * The stop of a runner started from the command line: requested once the process got SIGTERM or
 * SIGINT, which the command traps and passes on with request(). The runner asks between batches,
 * so a batch in progress commits before the process ends.
 */
#[Internal]
final class SignalStop implements RunnerStop
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    #[Override]
    public function requested(): bool
    {
        return $this->requested;
    }
}
