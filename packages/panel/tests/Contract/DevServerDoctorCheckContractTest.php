<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Panel\Doctor\Domain\Checks\DevServerCheck;
use Cbox\Cms\Panel\Tests\Doctor\Fakes\FakeDevServerProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against DevServerCheck, with a fake probe: the variable
 * unset against set in production.
 */
final class DevServerDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new DevServerCheck(new FakeDevServerProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new DevServerCheck(new FakeDevServerProbe('tally=http://localhost:5174', 'production'));
    }
}
