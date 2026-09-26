<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared IdempotencyStore contract suite against the Postgres store, as the app role on real
 * Postgres (GUARDRAILS 9: the same suite against the fake and the real adapter). The fake runs
 * the same cases in the Contract suite.
 */
final class PostgresIdempotencyStoreContractTest extends TestCase
{
    use IdempotencyStoreContract;
    use RealPostgres;

    #[Override]
    protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
    {
        return PostgresIdempotencySessions::at($clock);
    }
}
