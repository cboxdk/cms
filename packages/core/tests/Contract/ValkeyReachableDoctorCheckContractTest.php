<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ValkeyReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeValkeyProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against ValkeyReachableCheck, with fake probes: a Valkey that answers PING against one that refuses TCP.
 */
final class ValkeyReachableDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new ValkeyReachableCheck(new FakeValkeyProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new ValkeyReachableCheck(new FakeValkeyProbe(ProbeFailed::unavailable('Connection refused')));
    }
}
