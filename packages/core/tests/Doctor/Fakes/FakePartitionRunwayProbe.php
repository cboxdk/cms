<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use DateTimeImmutable;

/**
 * The coverage the test sets, or a failure. It remembers the time it was asked at.
 */
final class FakePartitionRunwayProbe implements PartitionRunwayProbe
{
    /**
     * @param  list<PartitionCoverage>  $runways
     */
    public function __construct(
        public array $runways = [],
        public ?ProbeFailed $failure = null,
    ) {}

    public ?DateTimeImmutable $askedAt = null;

    public function coverage(DateTimeImmutable $now): array
    {
        $this->askedAt = $now;

        if ($this->failure instanceof ProbeFailed) {
            throw $this->failure;
        }

        return $this->runways;
    }
}
