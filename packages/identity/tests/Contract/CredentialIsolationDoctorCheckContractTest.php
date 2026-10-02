<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\CredentialIsolationCheck;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeCredentialStoreProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against CredentialIsolationCheck, with a fake probe: an
 * app role without a privilege on the credential store against one with SELECT on its table.
 */
final class CredentialIsolationDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new CredentialIsolationCheck(new FakeCredentialStoreProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        $probe = new FakeCredentialStoreProbe;
        $probe->appPrivileges = ['SELECT on cms_identity.local_accounts'];

        return new CredentialIsolationCheck($probe);
    }
}
