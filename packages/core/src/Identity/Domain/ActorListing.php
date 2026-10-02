<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;

/**
 * What actor.list reads (PRD 5.16, 12.2), in the read transaction under its actor context: the
 * staff actors, only for an actor that holds a role whose permissions name actor.list, and nothing
 * otherwise. A profile is there for the context's own actor, and for every other actor only when
 * the context's classification access allows personal; otherwise it is null.
 */
#[Internal]
interface ActorListing
{
    /**
     * At most $limit staff actors in the order of their ids, after $after when it is not null.
     *
     * @return list<ListedActor>
     */
    public function staff(?ActorId $after, int $limit): array;
}
