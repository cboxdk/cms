<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Override;

/**
 * The Stopwatch on PHP's monotonic high-resolution timer, hrtime(true) (PRD 6.3): real time in
 * nanoseconds that never runs backwards, whatever the Clock says.
 */
#[Internal]
final readonly class HrtimeStopwatch implements Stopwatch
{
    #[Override]
    public function nanoseconds(): int
    {
        return hrtime(true);
    }
}
