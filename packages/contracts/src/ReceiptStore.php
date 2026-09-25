<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\UnstorableReceipt;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;

/**
 * The receipt store (GUARDRAILS 2.3, PRD 4 and 8.4): one stored receipt per committed changeset,
 * with the status of each projection the changeset affected.
 *
 * Transactions. store() and markProjection() run on the caller's connection. When the caller has a
 * transaction open, they run inside it, so the receipt commits or rolls back with the changeset
 * (PRD 6.2 phase 7, GUARDRAILS 4.1). They never begin, commit or roll back a transaction and never
 * use a savepoint. Without an open transaction each call commits on its own. find() reads on the
 * same connection, so it sees the caller's uncommitted writes and no one else's.
 *
 * Expiry is logical. A Standard receipt expires when the Clock is later than
 * RetentionClass::expiresAt() for its changeset: the time in the ChangesetId plus
 * RetentionClass::STANDARD_DAYS. From then on find() returns null and markProjection() ignores it.
 * An Evidence receipt never expires here; a policy decides when it goes. Removing the rows is a
 * separate job that drops whole partitions (PRD 4); until it has run, an expired receipt still
 * holds its changeset, so a second store() for it still fails.
 *
 * The shared contract suite is the testkit's ReceiptStoreContract. Every implementation runs it.
 */
#[Experimental]
interface ReceiptStore
{
    /**
     * Stores the receipt of a committed changeset.
     *
     * @throws UnstorableReceipt when the outcome is Rejected or DryRun: nothing was committed, so
     *                           there is no changeset to store the receipt under
     * @throws DuplicateReceipt when the store already holds a receipt for the changeset, expired
     *                          or not; the stored receipt is left as it was
     */
    public function store(Receipt $receipt): void;

    /**
     * The stored receipt for the changeset, or null when there is none or it has expired.
     */
    public function find(ChangesetId $changesetId): ?Receipt;

    /**
     * Records the status of one projection in the changeset's receipt. The status names the
     * projection. The statuses of the other projections are not touched.
     *
     * The call is idempotent per changeset and projection, so a subscriber can repeat it after a
     * crash. An acknowledgement is final: once a projection is acknowledged, marking it again,
     * pending or acknowledged at another time, changes nothing, and the first time is kept.
     *
     * Returns whether the store holds a receipt for the changeset that has not expired and lists
     * the projection. When it returns false, nothing was changed. A projection that acknowledges
     * after its receipt expired, or while it replays old events, is therefore not an error.
     */
    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool;
}
