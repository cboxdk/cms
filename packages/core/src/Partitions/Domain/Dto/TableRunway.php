<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;

/**
 * How far a table's partitions reach after a run: the exclusive end of its last managed
 * partition, or null when it has none. A write keyed at or after it fails with PartitionMissing.
 */
#[Experimental]
final readonly class TableRunway
{
    public function __construct(
        public string $table,
        public ?DateTimeImmutable $coveredUntil,
    ) {}
}
