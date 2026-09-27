<?php

declare(strict_types=1);

namespace Examples\Contract\Ids;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Cbox\Cms\Testkit\Ids\IdGeneratorContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdGenerator suite against the application's own generator. The suite drives the time
 * with a FakeClock, so the generator, and the one it wraps, must read the time from $clock.
 */
final class CountingIdGeneratorContractTest extends TestCase
{
    use IdGeneratorContract;

    #[Override]
    protected function generator(Clock $clock): IdGenerator
    {
        return new CountingIdGenerator(new SystemIdGenerator($clock));
    }
}
