<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fakes;

use Cbox\Cms\Core\Subscriptions\Domain\RunnerStop;
use Override;

/**
 * A stop that is requested after it was asked $rounds times, or never when null.
 */
final class StopAfterRounds implements RunnerStop
{
    private int $asked = 0;

    public function __construct(private readonly ?int $rounds = null) {}

    #[Override]
    public function requested(): bool
    {
        return $this->rounds !== null && $this->asked++ >= $this->rounds;
    }

    public function asked(): int
    {
        return $this->asked;
    }
}
