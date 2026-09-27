<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\AllowUrlFopenCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePhpSettingsProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against AllowUrlFopenCheck, with a fake probe: allow_url_fopen off against on.
 */
final class AllowUrlFopenDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new AllowUrlFopenCheck(new FakePhpSettingsProbe(allowUrlFopen: false));
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new AllowUrlFopenCheck(new FakePhpSettingsProbe(allowUrlFopen: true));
    }
}
