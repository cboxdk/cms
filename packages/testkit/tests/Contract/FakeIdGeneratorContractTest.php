<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Ids\IdGeneratorContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdGenerator contract suite against the fake generator with its default seed.
 */
final class FakeIdGeneratorContractTest extends TestCase
{
    use IdGeneratorContract;

    #[Override]
    protected function generator(Clock $clock): IdGenerator
    {
        return new FakeIdGenerator(clock: $clock);
    }
}
