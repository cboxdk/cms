<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRegistryCacheProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against RegistryCacheCheck, with fake probes: a cache built after vendor/ changed against one built before.
 */
final class RegistryCacheDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new RegistryCacheCheck(new FakeRegistryCacheProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new RegistryCacheCheck(new FakeRegistryCacheProbe(FakeRegistryCacheProbe::build(builtAt: new DateTimeImmutable('2026-01-01T09:00:00Z'))));
    }
}
