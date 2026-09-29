<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Receipts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * What the receipt store keeps for a committed changeset (PRD 8.4): the changeset, its retention
 * class, its commit position and the status of each projection it affected.
 *
 * The position is the xid8 of the command transaction, the one the changeset row and its events
 * carry (see CommitPosition): a read whose snapshot xmin is above it saw the changeset. The store
 * refuses a position that is not its transaction's with ForeignPosition.
 *
 * It holds only facts about the changeset, never the result of a call. The command kernel stores
 * it in the command transaction (PRD 6.2 phase 7), when the changeset is not yet committed and no
 * wait level has been reached, and the projections mark their status on it afterwards. Whether a
 * call's wait level was reached, and so whether the call is committed or committed_wait_timeout,
 * is decided for each call and put in the Receipt the call returns; a replay (PRD 6.1) finds this
 * record and decides again for the wait level it asks for.
 *
 * The projections are sorted by name, so two records with the same statuses are equal whatever
 * order they were given in. A projection appears at most once.
 */
#[Experimental]
final readonly class StoredReceipt
{
    /** @var list<ProjectionStatus> */
    public array $projections;

    /**
     * @param  list<ProjectionStatus>  $projections
     *
     * @throws InvalidReceipt when a projection is listed twice
     */
    public function __construct(
        public ChangesetId $changesetId,
        public RetentionClass $retentionClass,
        public CommitPosition $position,
        array $projections = [],
    ) {
        $this->projections = ProjectionStatus::listOf($projections);
    }
}
