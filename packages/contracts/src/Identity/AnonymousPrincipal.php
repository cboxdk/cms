<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The principal of a call without a credential (PRD 5.16, invariant 25): public reads through the
 * delivery API and public writes, such as submissions and registrations, which go through commands
 * with the anonymous actor. It reads only what is classified public.
 */
#[Experimental]
final readonly class AnonymousPrincipal implements Principal
{
    public function classificationCeiling(): ClassificationAccess
    {
        return ClassificationAccess::Public;
    }
}
