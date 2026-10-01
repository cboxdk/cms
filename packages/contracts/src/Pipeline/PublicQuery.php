<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A query anyone may run, the anonymous principal included, such as resolving a path to published
 * content (PRD 5.10, invariant 25). The kernel's authorization asks no permission of it; row level
 * security under the principal's context still decides what the read reaches, so the anonymous
 * principal reads only what is public. Every other query needs a role of the actor whose
 * permissions name it.
 */
#[Experimental]
interface PublicQuery extends Query {}
