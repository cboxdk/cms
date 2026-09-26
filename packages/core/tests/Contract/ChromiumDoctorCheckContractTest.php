<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ChromiumCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeToolProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against ChromiumCheck, with fake probes: Chromium downloaded against missing.
 */
final class ChromiumDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new ChromiumCheck(new FakeToolProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new ChromiumCheck(new FakeToolProbe(chromium: null));
    }
}
