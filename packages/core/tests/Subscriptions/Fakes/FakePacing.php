<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fakes;

use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Override;

/**
 * Real time for the runner's action tests: it moves only when the test or a wait moves it, and
 * remembers each wait.
 */
final class FakePacing implements Pacing
{
    /** @var list<int> */
    private array $sleeps = [];

    public function __construct(private int $now = 0) {}

    #[Override]
    public function milliseconds(): int
    {
        return $this->now;
    }

    #[Override]
    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->now += max(0, $milliseconds);
    }

    public function advance(int $milliseconds): void
    {
        $this->now += $milliseconds;
    }

    /**
     * @return list<int>
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
