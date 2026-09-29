<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The store kept the fragment; see FragmentWriteOutcome.
 */
#[Experimental]
final readonly class FragmentStored implements FragmentWriteOutcome
{
    public function __construct(public Fragment $fragment) {}
}
