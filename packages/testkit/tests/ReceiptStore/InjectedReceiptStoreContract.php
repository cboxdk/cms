<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract suite with the harness under test injected.
 */
final class InjectedReceiptStoreContract extends TestCase
{
    use ReceiptStoreContract;

    /** @var (Closure(Clock): ReceiptStoreHarness)|null */
    public ?Closure $harness = null;

    #[Override]
    protected function receiptStores(Clock $clock): ReceiptStoreHarness
    {
        $harness = $this->harness ?? throw new LogicException('No harness was injected.');

        return $harness($clock);
    }
}
