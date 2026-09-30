<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * What seed.entries reads in the command transaction, under the actor's context, one statement for
 * each whatever the size of the chunk.
 */
#[Internal]
interface SeedReader
{
    /**
     * The entries of the list that exist, in the list's order.
     *
     * @param  list<EntryId>  $entries
     * @return list<EntryId>
     */
    public function existing(array $entries): array;

    /**
     * The version of each node of the list by node id, null for a node absent or out of the
     * actor's reach.
     *
     * @param  list<NodeId>  $nodes
     * @return array<string, ?AggregateVersion>
     */
    public function nodes(array $nodes): array;
}
