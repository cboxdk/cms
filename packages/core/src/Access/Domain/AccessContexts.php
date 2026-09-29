<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\Principal;

/**
 * The AccessContext of a verified principal, as a surface needs it before it hands a call to the
 * command pipeline (PRD 5.10, 6.2): the anonymous context for the anonymous principal, and for an
 * actor its compiled grants, with public classification access and no regions when it holds none.
 *
 * It reads in a transaction of its own, which it ends before it returns, so the context it gives
 * is set again by the command transaction the call then runs in.
 */
#[Internal]
interface AccessContexts
{
    public function for(Principal $principal): AccessContext;
}
