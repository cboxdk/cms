<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared ReceiptStore contract suite against the Postgres store, as the app role on real
 * Postgres (GUARDRAILS 9: the same suite against the fake and the real adapter). The fake runs
 * the same cases in the Contract suite.
 */
final class PostgresReceiptStoreContractTest extends TestCase
{
    use RealPostgres;
    use ReceiptStoreContract;

    #[Override]
    protected function receiptStores(Clock $clock): ReceiptStoreHarness
    {
        return PostgresReceiptSessions::at($clock);
    }
}
