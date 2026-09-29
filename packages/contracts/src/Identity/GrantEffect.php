<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Whether a grant allows its role on its node and the subtree below, or denies it there (PRD 5.10).
 * A deny beats an allow it inherits, and the most specific grant wins: an allow below a deny
 * reaches its own subtree again.
 */
#[Experimental]
enum GrantEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
