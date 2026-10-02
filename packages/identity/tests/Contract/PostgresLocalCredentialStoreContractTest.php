<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Identity\Tests\LocalAccounts\PostgresLocalAccounts;
use Cbox\Cms\Testkit\Identity\LocalCredentialStoreContract;
use Cbox\Cms\Testkit\Identity\LocalCredentialStoreHarness;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared LocalCredentialStore contract suite against the identity module's Postgres store, in
 * the schema cms_identity on the identity role's connection, with actors the testkit's seeder
 * writes as the owner role (GUARDRAILS 9: the same suite against the fake and the real store). It
 * runs in the Contract suite beside the fake's and needs the services of `composer services:up`.
 */
final class PostgresLocalCredentialStoreContractTest extends TestCase
{
    use LocalCredentialStoreContract;
    use RealPostgres;

    #[Override]
    protected function harness(): LocalCredentialStoreHarness
    {
        return new PostgresLocalAccounts;
    }
}
