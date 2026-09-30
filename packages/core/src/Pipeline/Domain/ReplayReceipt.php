<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;

/**
 * The receipt a replay returns (PRD 6.1, 8.4): the original changeset from the receipt store, with
 * its retention class and its projection statuses as they are now, and the wait level the replay
 * asks for, which may differ from the first call's.
 *
 * A replay does not wait again. Whether the level is reached is decided by WaitLevelRule from the
 * statuses as they are now, the rule the first call's wait uses: commit always, origin once the
 * origin projection has acknowledged. A level not reached makes the replay committed_wait_timeout,
 * as a first call would be whose wait ran out, and the client may replay again later. The commit
 * position is the stored one (PRD 8.4). The consistency token, read after the first call's commit
 * (PRD 8.5), is not stored, so a replay carries none.
 */
#[Internal]
final readonly class ReplayReceipt
{
    public static function of(StoredReceipt $stored, WaitLevel $waitLevel): Receipt
    {
        return WaitLevelRule::reached($waitLevel, $stored->projections)
            ? Receipt::committed($stored->changesetId, $waitLevel, $stored->retentionClass, $stored->position, $stored->projections)
            : Receipt::committedWaitTimeout($stored->changesetId, $waitLevel, $stored->retentionClass, $stored->position, $stored->projections);
    }
}
