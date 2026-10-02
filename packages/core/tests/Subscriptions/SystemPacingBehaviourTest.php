<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PacingBehaviour against the monotonic clock and real waits the runner uses.
 */
final class SystemPacingBehaviourTest extends TestCase
{
    use PacingBehaviour;

    #[Override]
    protected function pacing(): Pacing
    {
        return new SystemPacing;
    }

    public function test_it_reads_the_monotonic_clock_in_whole_milliseconds(): void
    {
        $before = intdiv(hrtime(true), 1_000_000);
        $now = new SystemPacing()->milliseconds();
        $after = intdiv(hrtime(true), 1_000_000);

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }

    public function test_it_waits_a_whole_millisecond_for_a_wait_of_one_and_at_least_the_milliseconds_asked(): void
    {
        foreach ([1, 5] as $milliseconds) {
            $started = hrtime(true);
            new SystemPacing()->sleep($milliseconds);

            self::assertGreaterThanOrEqual($milliseconds * 1_000_000, hrtime(true) - $started, sprintf('A wait of %d ms ended early.', $milliseconds));
        }
    }
}
