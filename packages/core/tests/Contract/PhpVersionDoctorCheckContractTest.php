<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PhpVersionCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRuntimeProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against PhpVersionCheck, with fake probes: PHP 8.5.0 against 8.4.12.
 */
final class PhpVersionDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new PhpVersionCheck(new FakeRuntimeProbe(php: '8.5.0'));
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new PhpVersionCheck(new FakeRuntimeProbe(php: '8.4.12'));
    }
}
