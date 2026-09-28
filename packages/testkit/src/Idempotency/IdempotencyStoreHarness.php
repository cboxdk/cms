<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;

/**
 * What the shared IdempotencyStoreContract suite runs against: one idempotency store that several
 * sessions reach, each on its own connection, like ReceiptStoreHarness for the receipt store.
 */
#[Experimental]
interface IdempotencyStoreHarness
{
    /**
     * A new session on its own connection, with no transaction open.
     */
    public function session(): IdempotencyStoreSession;

    /**
     * Makes sure that no partition covers the record dates from $from to $to, both inclusive, so
     * complete() of a record whose date is in the range throws PartitionMissing. A record's date
     * is the Clock's time, or the changeset's time when that is later. A harness for a database
     * store removes every partition whose span overlaps the range, which can leave the rest of
     * those days uncovered too; the FakeIdempotencyStore leaves exactly the range uncovered. The
     * shared suite calls it before it writes in the range.
     */
    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void;

    /**
     * Starts a holder: another connection that begins a transaction, claims $key in $scope with
     * $hash without waiting, and completes the claim with $changesetId when one is given. The key
     * must be fresh for it. The method returns once the holder holds the claim, and the holder
     * ends its transaction as $end says $afterMilliseconds of real time later, whatever else
     * happens meanwhile.
     *
     * The shared suite calls claim() on the key right after, so the holder ends while that claim
     * waits, or after the claim has given up. A holder on a database runs beside the test, in a
     * process of its own, because the waiting claim blocks the test's process. A store whose
     * sessions share one process, like FakeIdempotencyStore, ends the holder instead once a
     * contested claim has waited $afterMilliseconds (FakeIdempotencyStore::whenWaiting()).
     */
    public function holdWhileWaiting(
        IdempotencyScope $scope,
        IdempotencyKey $key,
        ContentHash $hash,
        ?ChangesetId $changesetId,
        HolderEnd $end,
        int $afterMilliseconds,
    ): void;
}
