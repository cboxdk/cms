<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use InvalidArgumentException;

/**
 * Lists entries across types (PRD 5.4, 8.8, 13.4), version 1 of entry.list: a page of at most
 * $limit entries, newest first, each with its system columns, after the entry $after, or from the
 * newest when it is null, of the type $type, homed below the node $node (the node itself
 * included) and with the shared variant's release state $state, each filter left out when null.
 * Every actor may run it (ActorQuery), because it reads no more than the actor's own context
 * reaches: the entries whose home the actor reaches or that it owns.
 */
#[QueryName('entry.list', version: 1)]
#[Experimental]
final readonly class ListEntries implements ActorQuery
{
    /**
     * @throws InvalidArgumentException when the limit is outside ListLimit
     */
    public function __construct(
        public ?TypeId $type = null,
        public ?NodeId $node = null,
        public ?ReleaseState $state = null,
        public ?EntryId $after = null,
        public int $limit = ListLimit::DEFAULT,
    ) {
        ListLimit::checked($limit);
    }
}
