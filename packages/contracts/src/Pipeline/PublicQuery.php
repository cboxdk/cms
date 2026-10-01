<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A read of published content (PRD 5.10, invariant 25), which anyone may run: the anonymous
 * principal and every actor, without a permission. The query pipeline still sets the caller's
 * context, so row level security decides what the read reaches: for the anonymous principal only
 * what is public. Every other query needs a role whose permissions name it.
 */
#[Experimental]
interface PublicQuery extends Query {}
