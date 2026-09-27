<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared ReceiptStore suite against ArrayReceiptStore. ArrayReceiptHarness is the
 * database, and each ArrayReceiptSession it hands out is one connection to it, so the suite can
 * open a transaction on one connection and read on another.
 */
final class ArrayReceiptStoreContractTest extends TestCase
{
    use ReceiptStoreContract;

    #[Override]
    protected function receiptStores(Clock $clock): ReceiptStoreHarness
    {
        return new ArrayReceiptHarness($clock);
    }
}
