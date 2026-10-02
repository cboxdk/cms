<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Identity\BreachedPasswordsContract;
use Cbox\Cms\Testkit\Identity\BreachedPasswordsHarness;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared BreachedPasswords contract suite against the fake.
 */
final class FakeBreachedPasswordsContractTest extends TestCase
{
    use BreachedPasswordsContract;

    #[Override]
    protected function harness(): BreachedPasswordsHarness
    {
        return new FakeBreachedPasswords;
    }
}
