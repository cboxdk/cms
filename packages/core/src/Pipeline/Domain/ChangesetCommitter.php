<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;

/**
 * Phase 7 of the command pipeline (PRD 6.2, GUARDRAILS 4.1): writes a validated plan as one
 * changeset, with its audit, events and receipt, in one transaction on one connection. It claims
 * the envelope's idempotency key first, and commits only when every aggregate the pending changeset
 * read is still at the version it was read at, or still absent (invariant 11); otherwise it writes
 * nothing and answers with the stale reads. Only the kernel commits.
 */
#[Internal]
interface ChangesetCommitter
{
    public function commit(PendingChangeset $changeset): CommitOutcome;
}
