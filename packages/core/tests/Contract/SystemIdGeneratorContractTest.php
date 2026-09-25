<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Cbox\Cms\Testkit\Ids\IdGeneratorContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdGenerator contract suite against the real UUIDv7 generator.
 */
final class SystemIdGeneratorContractTest extends TestCase
{
    use IdGeneratorContract;

    #[Override]
    protected function generator(Clock $clock): IdGenerator
    {
        return new SystemIdGenerator($clock);
    }
}
