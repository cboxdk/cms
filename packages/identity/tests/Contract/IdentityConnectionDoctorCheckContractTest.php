<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\IdentityConnectionCheck;
use Cbox\Cms\Identity\Tests\Doctor\DoctorSettingsFixture;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeCredentialStoreProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against IdentityConnectionCheck, with a fake probe: an
 * identity connection that logs in as a role of its own against one that logs in as the app role.
 */
final class IdentityConnectionDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new IdentityConnectionCheck(new FakeCredentialStoreProbe, DoctorSettingsFixture::settings());
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        $probe = new FakeCredentialStoreProbe;
        $probe->identityLogin = 'cms_app';

        return new IdentityConnectionCheck($probe, DoctorSettingsFixture::settings());
    }
}
