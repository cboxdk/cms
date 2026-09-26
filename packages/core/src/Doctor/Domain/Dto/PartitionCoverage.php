<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateTimeImmutable;

/**
 * How far writes to a managed table can go from now: the exclusive end of the unbroken run of
 * attached partitions that starts with the partition holding now. A gap ends the run, because a
 * write in the gap fails. Null when no partition holds now.
 */
#[Internal]
final readonly class PartitionCoverage
{
    public function __construct(
        public string $table,
        public ?DateTimeImmutable $coveredUntil,
    ) {}
}
