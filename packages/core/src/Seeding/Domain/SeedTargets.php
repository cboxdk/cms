<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * Where a seed run writes: the nodes it spreads its entries over, every node the actor's regions
 * reach that can be the home of an entry, that is every node but a mount, sorted by id (node
 * commands come with block B2, so the seeder uses the structure that exists), and which entries of
 * a chunk exist already, so a chunk whose entries all exist is done without a command.
 *
 * Each read runs in a transaction of its own under the actor's context, which it ends before it
 * returns.
 */
#[Internal]
interface SeedTargets
{
    /**
     * @return list<NodeId>
     */
    public function nodes(AccessContext $access): array;

    /**
     * The entries of the list that exist and the context reads, in the list's order, as the
     * SeedReader of seed.entries reads them in the command transaction.
     *
     * @param  list<EntryId>  $entries
     * @return list<EntryId>
     */
    public function existing(AccessContext $access, array $entries): array;
}
