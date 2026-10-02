<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Identity\Tests\LocalAccounts\LocalLogins;
use Cbox\Cms\Identity\Tests\LocalAccounts\PostgresLocalAccounts;
use Cbox\Cms\Testkit\Login\LoginConnectionContract;
use Cbox\Cms\Testkit\Login\LoginConnectionHarness;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * The shared LoginConnection contract suite against the local connection over the Postgres store
 * on the identity connection, as the application runs it.
 */
final class PostgresLocalLoginConnectionContractTest extends TestCase
{
    use LoginConnectionContract;
    use RealPostgres;

    #[Override]
    protected function login(): LoginConnectionHarness
    {
        $accounts = new PostgresLocalAccounts;

        return new LocalLogins($accounts->store(), $accounts->actor(), clock: $accounts->clock());
    }
}
