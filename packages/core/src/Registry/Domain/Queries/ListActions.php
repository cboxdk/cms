<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;

/**
 * The actions the actor may run in the panel (GUARDRAILS 8, PRD 13.2, 13.4), version 1 of
 * action.list: every action of the compiled registry exposed on Inertia whose permission the
 * actor holds on some node, and the navigation entries the actor may open, which the command
 * palette is built from. It carries nothing: the actor is never a field of a query, so the
 * pipeline takes it from the credential alone. Every actor may run it without a permission
 * (ActorQuery), because it tells an actor no more than what the actor may do, and the anonymous
 * principal may not.
 */
#[QueryName('action.list', version: 1)]
#[Experimental]
final readonly class ListActions implements ActorQuery {}
