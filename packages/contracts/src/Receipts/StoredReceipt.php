<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Receipts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * What the receipt store keeps for a committed changeset (PRD 8.4): the changeset, its retention
 * class and the status of each projection it affected. The position from PRD 8.4, the consistency
 * token of PRD 8.5, arrives in M1 with the write path.
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
        array $projections = [],
    ) {
        $this->projections = ProjectionStatus::listOf($projections);
    }
}
