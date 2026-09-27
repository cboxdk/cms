<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ClaimToken;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\InvalidClaim;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;

/**
 * The idempotency store (GUARDRAILS 2.3, PRD 6.1 and 4): which changeset a command with a given
 * idempotency key committed, so a repeated call returns the original result instead of running
 * again.
 *
 * The claim model. Both methods run on the caller's connection, inside the caller's open command
 * transaction, and never begin, commit or roll back one or use a savepoint (PRD 6.2 phase 7,
 * GUARDRAILS 4.1). Without an open transaction they throw InvalidClaim.
 *
 * - claim() first takes a claim on (scope, key); the scope is the actor or source plus the command
 *   type. The claim lasts until the caller's transaction ends, by commit or rollback. When another
 *   open transaction holds it, claim() waits for that transaction to end, at most the wait budget,
 *   and then returns InFlight. A store on a database that ends a transaction after a time limit
 *   ends the wait sooner, with time left before that limit, so the caller gets InFlight and not a
 *   terminated session. Two transactions never hold the claim on one key at once.
 * - With the claim held, claim() looks up the completed record for the key: none gives Fresh with
 *   a token, the same content hash gives Replay with the stored changeset id, another hash gives
 *   Conflict. Every result but InFlight holds the claim until the transaction ends.
 * - complete() records the changeset for a Fresh claim, in the same transaction. The record
 *   commits with the changeset or not at all: after a rollback, or a commit without complete(),
 *   for example a rejected command, there is no record, and the next claim is Fresh. There is no
 *   release(); the end of the transaction releases the claim.
 *
 * The holding transaction sees its own record before commit: a second claim on the key in that
 * transaction gives Fresh with an equal token before complete(), and Replay or Conflict after it.
 * Other transactions see the record only after commit.
 *
 * Replay by reference. The record holds the changeset id, not a copy of the receipt. The caller
 * finds the receipt in the ReceiptStore, so the projection statuses it returns are current.
 *
 * Expiry is logical and follows the receipt. A record is live while the Clock is not later than
 * RetentionClass::Standard->expiresAt() for its changeset: the time in the ChangesetId plus 7 days
 * (PRD 4: idempotency keys are kept 7 days). A Replay therefore never names a changeset whose
 * Standard receipt has expired. Once a record has expired, the key is Fresh again, and complete()
 * replaces the record. Removing rows is a separate job that drops whole partitions.
 *
 * Partitions. A store on a database keeps records in a table partitioned by the record's date
 * (PRD 4, 4.2), with no DEFAULT partition: the Clock's time, or the changeset's time when that is
 * later. When no partition covers that date, complete() throws PartitionMissing and records
 * nothing. The caller then rolls back: a database has failed the transaction and takes no further
 * statements in it, and the rollback releases the claim, so the key stays fresh.
 *
 * The shared contract suite is the testkit's IdempotencyStoreContract. Every implementation runs it.
 */
#[Experimental]
interface IdempotencyStore
{
    /**
     * Claims the key in the caller's transaction and returns what the command should do: Fresh,
     * Replay, Conflict or InFlight. InFlight holds nothing and leaves the caller's transaction
     * usable.
     *
     * @return Fresh|Replay|Conflict|InFlight
     *
     * @throws InvalidClaim when the caller has no transaction open, or when its isolation level
     *                      would hide a commit made while the claim waited (a store on Postgres
     *                      needs READ COMMITTED)
     */
    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult;

    /**
     * Records that the Fresh claim with this token committed the changeset. The record is written
     * in the caller's transaction and becomes visible to others when it commits.
     *
     * @throws InvalidClaim when the caller has no transaction open, when the token is not from a
     *                      Fresh claim held by this transaction, or when it was already completed
     * @throws PartitionMissing when no partition covers the record's date, the later of the Clock's
     *                          time and the changeset's time; nothing is recorded, and the caller
     *                          rolls its transaction back
     */
    public function complete(ClaimToken $token, ChangesetId $changesetId): void;
}
