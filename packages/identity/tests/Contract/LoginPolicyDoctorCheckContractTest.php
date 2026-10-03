<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\LoginPolicyCheck;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeLoginPolicyProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against LoginPolicyCheck, with a fake probe: the module's
 * default policy in production against one that lets staff log in locally with a password there.
 */
final class LoginPolicyDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new LoginPolicyCheck(new FakeLoginPolicyProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new LoginPolicyCheck(new FakeLoginPolicyProbe(changes: ['staff' => ['local_factors' => 'password']]));
    }
}
