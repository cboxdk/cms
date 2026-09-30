<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Doctor\Domain\Checks\EventLagCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeEventLogProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against EventLagCheck, with fake probes: a critical subscription 200 ms behind against one 2 s behind.
 */
final class EventLagDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return $this->behind('2025-12-31T23:59:59.800Z');
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return $this->behind('2025-12-31T23:59:58Z');
    }

    private function behind(string $occurred): EventLagCheck
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));

        return new EventLagCheck(new FakeEventLogProbe([
            new SubscriptionLag(new SubscriptionName('fixture.purge'), Lane::Critical, new DateTimeImmutable($occurred), EventStream::Interactive),
        ]), $clock);
    }
}
