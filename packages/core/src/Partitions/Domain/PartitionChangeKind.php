<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What partition maintenance did to one partition.
 */
#[Experimental]
enum PartitionChangeKind: string
{
    case Created = 'created';
    case Detached = 'detached';
    case Finalized = 'finalized';
    case Dropped = 'dropped';
}
