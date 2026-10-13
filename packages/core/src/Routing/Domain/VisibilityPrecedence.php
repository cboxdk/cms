<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use DateTimeImmutable;

/**
 * The precedence of PRD 6.6 over the rungs M1 reaches (VisibilityDecision), strongest first, at a
 * time: the entry's lifecycle, the variant's release state, the placement's own state, the mount's
 * exception, the release of a type with stages, and the placement's window at the time, not the
 * state stored when the window was set, which nothing moves before the scheduler (PRD 6.7, block
 * B3).
 *
 * A reader that cannot read the entry, as the anonymous context cannot read the entry of a
 * withdrawn placement, gives no lifecycle; when the placement it can read is withdrawn, the
 * decision is the placement's withdrawal, which is sticky (invariant 7), so the public gets 410 for
 * a URL that is gone and not the 404 of an entry it cannot see.
 */
#[Internal]
final readonly class VisibilityPrecedence
{
    /**
     * @param  Stages|null  $stages  the stages of the entry's type, null when the reader cannot read
     *                               the entry, whose lifecycle then decides first
     */
    public static function decide(PlacementMatch $placement, ?Stages $stages, DateTimeImmutable $at): VisibilityStep
    {
        $decision = match (true) {
            ! $placement->lifecycle instanceof EntryLifecycle && $placement->visibility === Visibility::Withdrawn => VisibilityDecision::PlacementWithdrawn,
            $placement->lifecycle !== EntryLifecycle::Active => VisibilityDecision::EntryNotActive,
            $placement->release === ReleaseState::Withdrawn => VisibilityDecision::VariantWithdrawn,
            $placement->visibility === Visibility::Withdrawn => VisibilityDecision::PlacementWithdrawn,
            $placement->hiddenOnMount => VisibilityDecision::MountHidden,
            ! self::entryShowable($placement, $stages) => VisibilityDecision::NoReleasedRevision,
            default => self::window($placement, $at),
        };

        return new VisibilityStep(
            $decision,
            $at,
            $placement->lifecycle,
            $placement->release,
            $placement->visibility,
            $placement->window,
            in_array($decision, [VisibilityDecision::Visible, VisibilityDecision::BeforeWindow], true)
                ? Visibility::nextTransition($placement->window, $at)
                : null,
        );
    }

    /**
     * Whether the entry itself, apart from any placement of it, can be shown (PRD 6.6 rungs 2, 3
     * and 8): it is active, its variant is not withdrawn, and a type with stages has a released
     * revision. A placement of such an entry shows when its own state and window allow it.
     */
    public static function entryShowable(PlacementMatch $placement, ?Stages $stages): bool
    {
        return $placement->lifecycle === EntryLifecycle::Active
            && $placement->release !== ReleaseState::Withdrawn
            && ($stages === Stages::None || $placement->release === ReleaseState::Released);
    }

    private static function window(PlacementMatch $placement, DateTimeImmutable $at): VisibilityDecision
    {
        if ($placement->visibility === Visibility::Hidden || ! $placement->window instanceof TimeWindow) {
            return VisibilityDecision::PlacementHidden;
        }

        return match (Visibility::of($placement->window, $at)) {
            Visibility::Scheduled => VisibilityDecision::BeforeWindow,
            Visibility::Expired => VisibilityDecision::AfterWindow,
            default => VisibilityDecision::Visible,
        };
    }
}
