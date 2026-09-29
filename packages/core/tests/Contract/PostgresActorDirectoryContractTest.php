<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Testkit\Identity\ActorDirectoryContract;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared ActorDirectory contract suite against the core's Postgres adapter, as the app role on real
 * Postgres, with the actors and credentials the testkit's PostgresIdentitySeeder writes as the
 * owner role (GUARDRAILS 9: the same suite against the fake and the real adapter). It runs in the
 * Contract suite beside the fake's, so one filter runs both; it needs the services of
 * `composer services:up`, as the Postgres suite does.
 */
final class PostgresActorDirectoryContractTest extends TestCase
{
    use ActorDirectoryContract;
    use RealPostgres;

    #[Override]
    protected function identity(Clock $clock): IdentityHarness
    {
        return PostgresIdentity::at($clock);
    }
}
