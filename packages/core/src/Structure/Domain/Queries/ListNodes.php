<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use InvalidArgumentException;

/**
 * Lists the nodes the actor's regions reach (PRD 5.8, 5.10), version 1 of node.list: a page of at
 * most $limit nodes in tree order, a node before the nodes below it, after the node $after, or from
 * the first node when it is null. Every actor may run it (ActorQuery), because it reads no more
 * than the actor's own context reaches.
 */
#[QueryName('node.list', version: 1)]
#[Experimental]
final readonly class ListNodes implements ActorQuery
{
    /**
     * @throws InvalidArgumentException when the limit is outside ListLimit
     */
    public function __construct(
        public ?NodeId $after = null,
        public int $limit = ListLimit::DEFAULT,
    ) {
        ListLimit::checked($limit);
    }
}
