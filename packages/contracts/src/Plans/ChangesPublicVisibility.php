<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A mutation that can make content visible to the public (invariant 18), such as a placement's
 * window that opens. The kernel refuses a plan with such a mutation from an agent or a token: an
 * agent can prepare content, but a person makes it public.
 */
#[Experimental]
interface ChangesPublicVisibility extends Mutation
{
    /**
     * Whether this mutation makes content public, now or at a later time.
     */
    public function makesPublic(): bool;
}
