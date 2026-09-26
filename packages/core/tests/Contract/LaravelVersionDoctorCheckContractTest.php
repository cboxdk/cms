<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LaravelVersionCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRuntimeProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against LaravelVersionCheck, with fake probes: Laravel 13 against 12.
 */
final class LaravelVersionDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new LaravelVersionCheck(new FakeRuntimeProbe(laravel: '13.0.0'));
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new LaravelVersionCheck(new FakeRuntimeProbe(laravel: '12.40.1'));
    }
}
