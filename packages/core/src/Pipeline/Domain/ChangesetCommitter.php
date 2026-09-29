<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;

/**
 * Phase 7 of the command pipeline (PRD 6.2, GUARDRAILS 4.1): writes a validated plan as one
 * changeset, with its audit, events and its StoredReceipt in the ReceiptStore, on the connection of
 * the CommandTransaction the pipeline runs in, which already holds the claim on the envelope's
 * idempotency key. It never begins, ends or savepoints a transaction; the CommandTransaction
 * commits what it wrote once the pipeline has completed the key. It writes only when every
 * aggregate the pending changeset read is still at the version it was read at, or still absent
 * (invariant 11); otherwise it writes nothing and answers with the stale reads. Only the kernel
 * commits.
 */
#[Internal]
interface ChangesetCommitter
{
    public function commit(PendingChangeset $changeset): CommitOutcome;
}
