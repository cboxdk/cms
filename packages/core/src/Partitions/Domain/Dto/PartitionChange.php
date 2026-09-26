<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;

/**
 * One change partition maintenance made.
 */
#[Experimental]
final readonly class PartitionChange
{
    public function __construct(
        public string $table,
        public string $partition,
        public PartitionChangeKind $kind,
    ) {}
}
