<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;

/**
 * The purge fence refused the fragment; see FragmentWriteOutcome. $dependency is the first of the
 * fragment's dependencies, in their sorted order, whose live fence is at or above the fragment's
 * build position, and $purgedAt is that fence's position.
 */
#[Experimental]
final readonly class FragmentFenced implements FragmentWriteOutcome
{
    public function __construct(
        public FragmentKey $key,
        public DependencyKey $dependency,
        public CommitPosition $purgedAt,
        public CommitPosition $builtAt,
    ) {}
}
