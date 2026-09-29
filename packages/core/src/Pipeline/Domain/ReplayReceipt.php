<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;

/**
 * The receipt a replay returns (PRD 6.1, 8.4): the original changeset from the receipt store, with
 * its retention class and its projection statuses as they are now, and the wait level the replay
 * asks for, which may differ from the first call's.
 *
 * A replay does not wait again. Commit was reached when the changeset committed, so a replay at
 * Commit is committed. A level past commit counts as reached only when every projection the
 * receipt lists has acknowledged; otherwise the replay is committed_wait_timeout, as a first call
 * would be whose wait ran out, and the client may replay again later. The position, read after the
 * first call's commit (PRD 8.5), is not stored, so a replay carries none.
 */
#[Internal]
final readonly class ReplayReceipt
{
    public static function of(StoredReceipt $stored, WaitLevel $waitLevel): Receipt
    {
        $reached = $waitLevel === WaitLevel::Commit || array_all(
            $stored->projections,
            static fn (ProjectionStatus $status): bool => $status->state === ProjectionState::Acknowledged,
        );

        return $reached
            ? Receipt::committed($stored->changesetId, $waitLevel, $stored->retentionClass, $stored->projections)
            : Receipt::committedWaitTimeout($stored->changesetId, $waitLevel, $stored->retentionClass, $stored->projections);
    }
}
