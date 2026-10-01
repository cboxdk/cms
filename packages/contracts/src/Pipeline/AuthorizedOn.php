<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The Aggregates of a command whose permission is checked on nodes (PRD 5.10, 6.2 phase 2): an
 * actor may run the command only when one of its roles whose permissions name the command reaches
 * every target, in the target's locale. A change of an entry's content is checked on the entry's
 * home, a change of a placement on the placement's node, and a composite command on each node its
 * sub-plans change. The kernel refuses a command whose aggregates name no target.
 */
#[Experimental]
interface AuthorizedOn extends Aggregates
{
    /**
     * Every node the command's permission must hold on, each with its locale; none when the
     * aggregate that would name one is absent.
     *
     * @return list<AuthorizationTarget>
     */
    public function authorizationTargets(): array;
}
