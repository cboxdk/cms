<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Identity\ActorDirectoryContract;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared ActorDirectory contract suite against the in-memory fake.
 */
final class FakeActorDirectoryContractTest extends TestCase
{
    use ActorDirectoryContract;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return new FakeIdentity($clock);
    }
}
