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

    /** A table with a managed name that was not a partition was attached again, because its span is wanted and not past retention. */
    case Reattached = 'reattached';
}
