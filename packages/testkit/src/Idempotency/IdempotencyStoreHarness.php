<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
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
}
