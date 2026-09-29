<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Partition;
use Cbox\Cms\Core\Partitions\Domain\SequencePartition;

/**
 * A managed partition found in the catalog.
 */
#[Internal]
final readonly class CatalogPartition
{
    public function __construct(
        public Partition|SequencePartition $partition,
        public PartitionState $state,
    ) {}
}
