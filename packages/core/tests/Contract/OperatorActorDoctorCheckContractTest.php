<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\OperatorActorCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeOperatorProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against OperatorActorCheck, with fake probes: an active service operator against an installation without one.
 */
final class OperatorActorDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new OperatorActorCheck(new FakeOperatorProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new OperatorActorCheck(FakeOperatorProbe::none());
    }
}
