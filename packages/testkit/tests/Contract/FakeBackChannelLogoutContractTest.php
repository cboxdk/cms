<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Signals\BackChannelLogoutContract;
use Cbox\Cms\Testkit\Signals\BackChannelLogoutHarness;
use Cbox\Cms\Testkit\Signals\FakeBackChannelLogoutReceiver;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared BackChannelLogoutReceiver contract suite against the fake.
 */
final class FakeBackChannelLogoutContractTest extends TestCase
{
    use BackChannelLogoutContract;

    #[Override]
    protected function logouts(): BackChannelLogoutHarness
    {
        return new FakeBackChannelLogoutReceiver;
    }
}
