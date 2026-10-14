<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryTypes;

/**
 * The targets of the relations a plan writes (PRD 5.11, 6.2 phase 5): the type of each entry that
 * exists and that the actor can read, read in one statement for any number of entries, in the
 * command transaction and under its actor context, so an entry outside the actor's regions reads
 * as absent. It takes no lock: an entry is never deleted while a relation names it, because the
 * column of a to-one relation has a foreign key ON DELETE RESTRICT, and the relations table
 * holds the same for every target. The nodes a plan's links name are read the same way.
 */
#[Internal]
interface RelationTargets
{
    /**
     * @param  list<EntryId>  $entries  each once
     */
    public function types(array $entries): EntryTypes;

    /**
     * The nodes a plan's links name that exist and that the actor can read (B2-T76), in one
     * statement for any number of nodes.
     *
     * @param  list<NodeId>  $nodes  each once
     * @return list<NodeId>
     */
    public function nodes(array $nodes): array;
}
