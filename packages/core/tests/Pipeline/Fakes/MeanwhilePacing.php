<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Closure;
use Override;

/**
 * Real time for the wait after commit that moves only when the wait sleeps, as FakePacing, and
 * runs what the test lets happen meanwhile once the wait has slept a number of times, such as a
 * subscriber acknowledging a projection while the call waits.
 */
final class MeanwhilePacing implements Pacing
{
    public readonly FakePacing $time;

    /**
     * @param  (Closure(): void)|null  $meanwhile  runs once, after the sleep numbered $after
     */
    public function __construct(private ?Closure $meanwhile = null, private readonly int $after = 1)
    {
        $this->time = new FakePacing;
    }

    #[Override]
    public function milliseconds(): int
    {
        return $this->time->milliseconds();
    }

    #[Override]
    public function sleep(int $milliseconds): void
    {
        $this->time->sleep($milliseconds);

        if ($this->meanwhile instanceof Closure && count($this->time->sleeps()) === $this->after) {
            $meanwhile = $this->meanwhile;
            $this->meanwhile = null;
            $meanwhile();
        }
    }

    /**
     * @return list<int>
     */
    public function sleeps(): array
    {
        return $this->time->sleeps();
    }
}
