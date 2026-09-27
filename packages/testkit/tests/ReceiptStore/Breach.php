<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

/**
 * How a broken store breaks the contract.
 */
enum Breach
{
    /** begin, commit and rollBack do nothing, so every write commits at once. */
    case IgnoresTransactions;

    /** store() opens a transaction when none is open and leaves it open. */
    case BeginsTransaction;

    /** find() returns a receipt as it was stored, so a replay never sees a later mark. */
    case FreezesStoredReceipt;

    /** A second receipt for a changeset replaces the first without an error. */
    case OverwritesDuplicate;

    /** markProjection() gives every projection in the receipt the new status. */
    case MarksEveryProjection;

    /** A later acknowledgement replaces the first one. */
    case ReacknowledgesProjection;

    /** An Evidence receipt expires like a Standard one. */
    case ExpiresEvidence;

    /** uncover() does nothing, so a receipt at any changeset time is stored. */
    case CoversEveryDate;

    /** A transaction that met PartitionMissing takes further calls. */
    case KeepsFailedTransactions;
}
