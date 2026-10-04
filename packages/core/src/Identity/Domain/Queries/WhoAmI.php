<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;

/**
 * Who am I (PRD 5.16, 13.4), version 1 of actor.me: the principal's own actor with its class, state
 * and version, its own profile, and the grants it holds. It carries nothing: the actor is never a
 * field of a query, so the pipeline takes it from the credential alone. Every actor may run it
 * without a permission (ActorQuery), because it reads no more than the actor's own self, and the
 * anonymous principal may not.
 */
#[QueryName('actor.me', version: 1)]
#[Experimental]
final readonly class WhoAmI implements ActorQuery {}
