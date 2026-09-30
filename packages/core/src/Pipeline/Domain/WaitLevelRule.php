<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;

/**
 * When a committed changeset has reached the wait level a call asks for (PRD 8.4), read from the
 * projection statuses of its receipt. The first call's wait (AwaitWaitLevel) and a replay's receipt
 * (ReplayReceipt) decide it by this one rule, so both give the same outcome for the same statuses.
 *
 * - Commit is reached when the changeset committed.
 * - Origin is reached when the projection ORIGIN has acknowledged, which the kernel's invalidation
 *   subscriber does once it has purged the server fragments of the changeset's content (and taken
 *   the edge purge). A receipt that lists no ORIGIN changed nothing a fragment holds, so it has
 *   reached origin at commit. The other projections a receipt lists do not hold origin back.
 * - Edge, verified and propagated are reached when every projection the receipt lists has
 *   acknowledged. The projections that confirm each of them (the CDN's purge, the probe, the
 *   search index) come with the invalidation in full scale (MILESTONES M3 point 4), which narrows
 *   this rule per level.
 */
#[Internal]
final readonly class WaitLevelRule
{
    /** The projection of the server fragments, which the invalidation subscriber acknowledges. */
    public const string ORIGIN = 'origin';

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public static function reached(WaitLevel $level, array $projections): bool
    {
        return match ($level) {
            WaitLevel::Commit => true,
            WaitLevel::Origin => array_all(
                $projections,
                static fn (ProjectionStatus $status): bool => $status->projection->value !== self::ORIGIN || $status->state === ProjectionState::Acknowledged,
            ),
            WaitLevel::Edge, WaitLevel::Verified, WaitLevel::Propagated => array_all(
                $projections,
                static fn (ProjectionStatus $status): bool => $status->state === ProjectionState::Acknowledged,
            ),
        };
    }
}
