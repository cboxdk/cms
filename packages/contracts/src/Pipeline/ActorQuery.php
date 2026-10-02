<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A query every actor may run without a permission, such as listing the nodes its own regions
 * reach (PRD 5.10): what it reads is bounded by the actor's context alone, so naming it in a role
 * would add nothing. The anonymous principal may not run it. Row level security under the actor's
 * context still decides what the read reaches. An actor that acts on behalf of others runs it as
 * any actor does, with the context its chain leaves it (PRD 5.16).
 */
#[Experimental]
interface ActorQuery extends Query {}
