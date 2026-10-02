<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Testkit\Signals\FakeSecurityEventReceiver;
use Cbox\Cms\Testkit\Signals\SecurityEventHarness;
use Cbox\Cms\Testkit\Signals\SecurityEventReceiverContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared SecurityEventReceiver contract suite against the fake.
 */
final class FakeSecurityEventReceiverContractTest extends TestCase
{
    use SecurityEventReceiverContract;

    #[Override]
    protected function events(): SecurityEventHarness
    {
        return new FakeSecurityEventReceiver;
    }
}
