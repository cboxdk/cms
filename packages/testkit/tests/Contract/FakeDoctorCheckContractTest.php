<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against the fake check.
 */
final class FakeDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return FakeDoctorCheck::passing(new CheckId('fake.check'), requires: [new CheckId('fake.dependency')]);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return FakeDoctorCheck::failing(new CheckId('fake.check'), FailureKind::Unavailable, requires: [new CheckId('fake.dependency')]);
    }
}
