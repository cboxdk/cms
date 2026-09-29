<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * What an addon may do through the kernel, as its manifest declares it and the installation
 * approves it (PRD 13.1). The kernel enforces it at its boundary (invariant 21):
 *
 * - reads: the highest classification of fields the kernel hands the addon's hooks. A hook sees a
 *   plan view with the fields up to the lower of this and the actor's classification access, and
 *   a transform hook cannot change a field above it. Public, the default, hands it only public
 *   fields.
 */
#[Experimental]
final readonly class AddonCapabilities
{
    public function __construct(
        public ClassificationAccess $reads = ClassificationAccess::Public,
    ) {}
}
