<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Build;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Implemented by a package's service provider to declare where cms:build looks for its actions,
 * commands and hooks (PRD 13.2).
 *
 * cms:build asks every registered provider that implements it, deferred providers included. A
 * package that declares nothing has nothing in the registry, even when its classes carry the
 * attributes.
 */
#[Experimental]
interface DeclaresScanRoots
{
    /**
     * @return list<ScanRoot>
     */
    public function scanRoots(): array;
}
