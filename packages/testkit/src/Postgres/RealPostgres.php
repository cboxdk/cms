<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Runs a Testbench test case against real Postgres (GUARDRAILS 9).
 *
 * Testbench calls setUpRealPostgres() after the application has booted and tearDownRealPostgres()
 * before it is destroyed. The work is in PostgresHarness. The default connection must be the
 * app role; the owner role's connection is `pgsql_owner` unless the test case overrides
 * postgresOwnerConnection().
 *
 *     pest()->extend(TestCase::class)->use(RealPostgres::class)->in('Postgres');
 */
#[Experimental]
trait RealPostgres
{
    private ?PostgresHarness $realPostgresHarness = null;

    protected function setUpRealPostgres(): void
    {
        $this->realPostgresHarness = PostgresHarness::start($this->app, static::class, $this->postgresOwnerConnection());
    }

    protected function tearDownRealPostgres(): void
    {
        $harness = $this->realPostgresHarness;
        $this->realPostgresHarness = null;

        $harness?->finish();
    }

    protected function postgresOwnerConnection(): string
    {
        return 'pgsql_owner';
    }
}
