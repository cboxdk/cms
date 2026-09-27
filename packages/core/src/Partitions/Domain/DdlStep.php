<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A step of partition maintenance that takes locks.
 */
#[Experimental]
enum DdlStep: string
{
    /** The advisory lock that lets one run at a time maintain partitions. */
    case Lock = 'lock';

    /** CREATE TABLE ... (LIKE parent) and ATTACH PARTITION, in one transaction. */
    case Create = 'create';

    /**
     * ALTER TABLE ... ATTACH PARTITION of a table with a managed name that is not a partition and
     * whose span is wanted, with the parent's row security and grants, in one transaction.
     */
    case Attach = 'attach';

    /** ALTER TABLE ... DETACH PARTITION ... CONCURRENTLY, outside a transaction. */
    case Detach = 'detach';

    /** ALTER TABLE ... DETACH PARTITION ... FINALIZE, for a detach that was interrupted. */
    case Finalize = 'finalize';

    /** DROP TABLE of a detached partition. */
    case Drop = 'drop';
}
