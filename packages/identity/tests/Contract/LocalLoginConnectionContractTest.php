<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Identity\Tests\LocalAccounts\LocalLogins;
use Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore;
use Cbox\Cms\Testkit\Login\LoginConnectionContract;
use Cbox\Cms\Testkit\Login\LoginConnectionHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared LoginConnection contract suite against the identity module's local connection, of the
 * flow Direct, over the testkit's FakeLocalCredentialStore and the real Argon2id hasher at cheap
 * parameters.
 */
final class LocalLoginConnectionContractTest extends TestCase
{
    use LoginConnectionContract;

    #[Override]
    protected function login(): LoginConnectionHarness
    {
        $store = new FakeLocalCredentialStore;

        return new LocalLogins($store, $store->actor());
    }
}
