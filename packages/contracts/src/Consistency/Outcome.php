<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a command ended (PRD 6.1, Resultater). The values are the names in the PRD and the JSON form.
 */
#[Experimental]
enum Outcome: string
{
    /** Authorization, validation or a conflict stopped the command. Nothing was committed. */
    case Rejected = 'rejected';

    /** The changeset was committed and the requested wait level was reached. */
    case Committed = 'committed';

    /** The changeset was committed, but the wait level was not reached within the deadline. */
    case CommittedWaitTimeout = 'committed_wait_timeout';

    /** The plan was computed and nothing was committed. */
    case DryRun = 'dry_run';

    /**
     * Whether a changeset exists. Only these receipts carry a ChangesetId and are stored.
     */
    public function isCommitted(): bool
    {
        return $this === self::Committed || $this === self::CommittedWaitTimeout;
    }
}
