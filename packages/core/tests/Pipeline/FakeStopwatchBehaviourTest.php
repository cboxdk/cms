<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Override;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * StopwatchBehaviour against the fake the pipeline's action tests use, which advances only when
 * told to.
 */
final class FakeStopwatchBehaviourTest extends TestCase
{
    use StopwatchBehaviour;

    #[Override]
    protected function stopwatch(): Stopwatch
    {
        return new FakeStopwatch(1_000);
    }

    #[Override]
    protected function pass(Stopwatch $stopwatch, int $nanoseconds): void
    {
        Assert::assertInstanceOf(FakeStopwatch::class, $stopwatch);
        $stopwatch->advance($nanoseconds);
    }
}
