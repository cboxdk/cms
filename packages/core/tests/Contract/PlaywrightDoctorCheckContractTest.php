<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PlaywrightCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeToolProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against PlaywrightCheck, with fake probes: Playwright installed against missing.
 */
final class PlaywrightDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new PlaywrightCheck(new FakeToolProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new PlaywrightCheck(new FakeToolProbe(playwright: null));
    }
}
