<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * Locks the row of one kind of aggregate and reads its version, for the commit's version check
 * (PRD 6.2 phase 7, invariants 11 and 37). The kernel registers one per kind (VersionLocks): the
 * core's for the actor, and the command tasks' for the aggregates their commands read.
 *
 * lock() runs on the command transaction's connection, inside it, and holds the lock until the
 * transaction ends. It never begins, ends or savepoints a transaction. It answers with the version
 * the aggregate has now, or null when it does not exist. The commit calls it once per aggregate,
 * in the order of the aggregate keys, so two commits lock the same aggregates in the same order.
 */
#[Internal]
interface VersionLock
{
    /**
     * The kind of aggregate it locks: the part of the aggregate key before the first colon, such
     * as `actor` for "actor:<uuid>".
     */
    public function kind(): string;

    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion;
}
