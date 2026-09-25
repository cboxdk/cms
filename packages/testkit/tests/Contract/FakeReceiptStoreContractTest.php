<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared ReceiptStore contract suite against the in-memory fake and its sessions.
 */
final class FakeReceiptStoreContractTest extends TestCase
{
    use ReceiptStoreContract;

    #[Override]
    protected function receiptStores(Clock $clock): ReceiptStoreHarness
    {
        return new FakeReceiptStore($clock);
    }
}
