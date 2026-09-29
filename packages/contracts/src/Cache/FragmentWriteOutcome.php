<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What FragmentStore::write() returns. There are exactly two outcomes, and a store returns no
 * other class:
 *
 * - FragmentStored: the fragment replaced whatever the key held, and the reverse index lists it
 *   under each of its dependencies.
 * - FragmentFenced: a dependency was purged at or above the fragment's build position; the store
 *   kept nothing, and the key holds what it held before. The caller serves what it built to this
 *   request only, with `no-store` (PRD 8.12 point 1).
 */
#[Experimental]
interface FragmentWriteOutcome {}
