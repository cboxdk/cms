<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;

/**
 * Reads one entry as the editor opens it (PRD 5.4, 5.7, 13.4), version 1 of entry.get: its type,
 * home node and lifecycle, every variant head with the fields of its draft revision, and its
 * placements with their locales and windows. Every actor may run it (ActorQuery), because it
 * reads no more than the actor's own context reaches: an entry whose home the actor does not
 * reach and does not own reads as absent.
 */
#[QueryName('entry.get', version: 1)]
#[Experimental]
final readonly class GetEntry implements ActorQuery
{
    public function __construct(public EntryId $entry) {}
}
