<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\Replay;

/**
 * How a broken store breaks the contract.
 */
enum IdempotencyBreach
{
    /** complete() commits at once and opens a new transaction, so the record ignores a rollback. */
    case CommitsCompleteAtOnce;

    /** complete() records nothing, so a key is never replayed. */
    case ForgetsRecords;

    /** A Fresh claim is released at once, so another transaction can take the key. */
    case ReleasesFreshClaims;

    /** A Fresh claim that is not completed stays held after its transaction ends. */
    case KeepsClaimsWithoutComplete;

    /** A Replay or Conflict does not hold the claim. */
    case ReleasesReplays;

    /** The same key with another hash replays the stored changeset. */
    case IgnoresHash;

    /** Keys are unique across all actors, sources and command types. */
    case IgnoresScope;

    /** complete() accepts a token this transaction does not hold. */
    case AcceptsAnyToken;

    /** claim() and complete() open a transaction when none is open. */
    case RunsOutsideTransactions;

    /** uncover() does nothing, so a record at any date is written. */
    case CoversEveryDate;

    /** A transaction that met PartitionMissing takes further claims. */
    case KeepsFailedTransactions;

    /** The lookup ends with the Clock's UTC day, so a record created on a later day is not found. */
    case LooksUpToTheEndOfTheClocksDay;
}
