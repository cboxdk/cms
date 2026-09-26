<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePartitionRunwayProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck contract suite against PartitionRunwayCheck, with fake probes: 15 days of partitions against 2.
 */
final class PartitionRunwayDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new PartitionRunwayCheck(new FakePartitionRunwayProbe([new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-01-16T00:00:00Z'))]), new FakeClock, 7);
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new PartitionRunwayCheck(new FakePartitionRunwayProbe([new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-01-03T00:00:00Z'))]), new FakeClock, 7);
    }
}
