<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\WriteResult;
use Closure;

/**
 * The command transaction (PRD 6.1, 6.2 phase 7, GUARDRAILS 4.1): the one transaction, on the one
 * connection, that a write runs in from its idempotency claim to its commit. The command pipeline
 * runs every phase inside it, so the claim on the key lasts until the changeset commits, and the
 * idempotency store, the receipt store and the ChangesetCommitter all write in it. The pipeline
 * never begins or ends it itself; this port does.
 *
 * run() begins the transaction at READ COMMITTED, which the stores need to see a commit made while
 * a claim waited, and calls the work once. It commits when the work's result committed a
 * changeset, and rolls back every other result, a rejection or a dry run, so nothing they wrote,
 * the claim included, outlives the call. When the work throws, it rolls back and throws the same
 * exception. It never nests: a caller that already has a transaction open gets
 * CommandTransactionOpen, because savepoints are forbidden (PRD 4.2) and a claim must end with the
 * command's own transaction.
 */
#[Internal]
interface CommandTransaction
{
    /**
     * @param  Closure(): WriteResult  $work
     *
     * @throws CommandTransactionOpen when a transaction is already open on the connection
     */
    public function run(Closure $work): WriteResult;
}
