<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a commit ended (PRD 6.2 phase 7). There are exactly two, in Domain\Dto: Committed with the
 * receipt of the new changeset, and VersionConflict with the stale reads. Only Committed wrote
 * anything. The idempotency outcomes, a replay, idempotency_conflict and idempotency_in_flight, are
 * decided by the command pipeline before it resolves anything (PRD 6.1), so a commit never meets
 * them.
 */
#[Internal]
interface CommitOutcome {}
