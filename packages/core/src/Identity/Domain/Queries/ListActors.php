<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use InvalidArgumentException;

/**
 * Lists the staff actors with their profiles (PRD 5.16, 12.2), version 1 of actor.list: a page of
 * at most $limit actors in the order of their ids, after the actor $after, or from the first actor
 * when it is null. It needs a role whose permissions name actor.list.
 */
#[QueryName('actor.list', version: 1)]
#[Experimental]
final readonly class ListActors implements Query
{
    /**
     * @throws InvalidArgumentException when the limit is outside ListLimit
     */
    public function __construct(
        public ?ActorId $after = null,
        public int $limit = ListLimit::DEFAULT,
    ) {
        ListLimit::checked($limit);
    }
}
