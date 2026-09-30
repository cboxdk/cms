<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Results\CatalogError;

/**
 * A WriteAction that can refuse its command for what resolve() read (PRD 6.2), such as a slug
 * another placement below the node has, or a target the actor's grants do not reach. The kernel
 * asks it after the authorize phase and before plan(), and rejects the call with the errors it
 * gives, first the one that decides the call. Like resolve() and plan(), it is pure: it looks only
 * at the command and the aggregates.
 *
 * @template TCommand of Command
 * @template TAggregates of Aggregates
 */
#[Experimental]
interface RefusesCommand
{
    /**
     * The reasons to refuse the command, or none to go on.
     *
     * @param  TCommand  $command
     * @param  TAggregates  $aggregates
     * @return list<CatalogError>
     */
    public function refusals(Command $command, Aggregates $aggregates): array;
}
