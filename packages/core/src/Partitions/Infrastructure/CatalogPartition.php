<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Domain\Partition;

/**
 * A managed partition found in the catalog.
 */
#[Internal]
final readonly class CatalogPartition
{
    public function __construct(
        public Partition $partition,
        public PartitionState $state,
    ) {}
}
