<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Identity\Tests\BreachedPasswords\HibpRangeService;
use Cbox\Cms\Testkit\Identity\BreachedPasswordsContract;
use Cbox\Cms\Testkit\Identity\BreachedPasswordsHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared BreachedPasswords contract suite against HibpBreachedPasswords, with the range API
 * faked behind the egress gateway (HibpRangeService).
 */
final class HibpBreachedPasswordsContractTest extends TestCase
{
    use BreachedPasswordsContract;

    #[Override]
    protected function harness(): BreachedPasswordsHarness
    {
        return new HibpRangeService;
    }
}
