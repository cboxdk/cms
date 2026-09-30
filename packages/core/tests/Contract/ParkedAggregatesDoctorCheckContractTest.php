<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Doctor\Domain\Checks\ParkedAggregatesCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeEventLogProbe;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against ParkedAggregatesCheck, with fake probes: nothing parked against 3 parked aggregates.
 */
final class ParkedAggregatesDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new ParkedAggregatesCheck(new FakeEventLogProbe);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new ParkedAggregatesCheck(new FakeEventLogProbe(parkedCounts: [new ParkedCount(new SubscriptionName('fixture.purge'), 3)]));
    }
}
