<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A VersionLock that also locks a run of aggregates of its kind in one statement, in the order
 * given, which the commit gives in aggregate key order (GUARDRAILS 4.1).
 */
#[Internal]
interface BatchVersionLock extends VersionLock
{
    /**
     * The version of each aggregate by its aggregate key, null for one that does not exist.
     *
     * @param  non-empty-list<AggregateRef>  $aggregates  of its kind, sorted by aggregate key
     * @return array<string, ?AggregateVersion>
     */
    public function lockAll(array $aggregates, LockStrength $strength): array;
}
