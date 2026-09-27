<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Storage\PartitionMissing;

/**
 * The receipt store (GUARDRAILS 2.3, PRD 4 and 8.4): one stored receipt per committed changeset,
 * with the status of each projection the changeset affected.
 *
 * It stores facts about the changeset, a StoredReceipt, and never the result of a call: the
 * outcome and the wait level of a Receipt are not stored. The receipt is written in the command
 * transaction (PRD 6.2 phase 7), before the wait for any level past commit, so the store cannot
 * know whether a call's wait level is reached. The command kernel decides that for each call, a
 * replay included (PRD 6.1), from the wait level the call asks for and the projections' status.
 *
 * Transactions. store() and markProjection() run on the caller's connection. When the caller has a
 * transaction open, they run inside it, so the receipt commits or rolls back with the changeset
 * (PRD 6.2 phase 7, GUARDRAILS 4.1). They never begin, commit or roll back a transaction and never
 * use a savepoint. Without an open transaction each call commits on its own. find() reads on the
 * same connection, so it sees the caller's uncommitted writes and no one else's. A store() of a
 * changeset that another open transaction has stored waits until that transaction ends, then
 * throws DuplicateReceipt when it committed and stores when it rolled back. The duplicate always
 * comes from store(), never from the caller's commit.
 *
 * Expiry is logical. A Standard receipt expires when the Clock is later than
 * RetentionClass::expiresAt() for its changeset: the time in the ChangesetId plus
 * RetentionClass::STANDARD_DAYS. From then on find() returns null and markProjection() ignores it.
 * An Evidence receipt never expires here; a policy decides when it goes. Removing the rows is a
 * separate job that drops whole partitions (PRD 4); until it has run, an expired receipt still
 * holds its changeset, so a second store() for it still fails.
 *
 * Partitions. A store on a database keeps receipts in tables partitioned by the changeset's time
 * (PRD 4, 4.2), with no DEFAULT partition. When no partition covers the changeset's time, store()
 * throws PartitionMissing and stores nothing. Inside a transaction the caller then rolls back: a
 * database has failed the transaction and takes no further statements in it, so nothing the
 * transaction wrote before is kept.
 *
 * The shared contract suite is the testkit's ReceiptStoreContract. Every implementation runs it.
 */
#[Experimental]
interface ReceiptStore
{
    /**
     * Stores the receipt of a committed changeset.
     *
     * @throws DuplicateReceipt when the store already holds a receipt for the changeset, expired
     *                          or not; the stored receipt is left as it was
     * @throws PartitionMissing when no partition covers the changeset's time; nothing is stored,
     *                          and a caller inside a transaction rolls it back
     */
    public function store(StoredReceipt $receipt): void;

    /**
     * The stored receipt for the changeset, or null when there is none or it has expired.
     */
    public function find(ChangesetId $changesetId): ?StoredReceipt;

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
