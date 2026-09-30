<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\TimeWindow;
use DateTimeImmutable;

/**
 * The visibility state of a placement in one language (PRD 5.7, 6.4): hidden, scheduled, live,
 * expired or withdrawn. A command that sets the window derives the state from the window at the
 * time it commits: no window is hidden, a window that has not begun is scheduled, one that holds
 * the time is live, and one that has ended is expired. Withdrawn is set only by a withdrawal, and
 * only reinstate leaves it (invariant 7).
 *
 * The state is stored as the command left it. In M1 nothing moves it when the time passes a window's
 * start or end, because planned transitions and the scheduler come with block B3; a read therefore
 * decides visibility from the window at its own time (visibleAt()), and next_transition_at says
 * when the stored state next goes stale (PRD 6.7).
 */
#[Experimental]
enum Visibility: string
{
    case Hidden = 'hidden';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    /**
     * The state a window gives at the time: hidden without a window.
     */
    public static function of(?TimeWindow $window, DateTimeImmutable $at): self
    {
        return match (true) {
            ! $window instanceof TimeWindow => self::Hidden,
            $window->from instanceof DateTimeImmutable && $at < $window->from => self::Scheduled,
            $window->until instanceof DateTimeImmutable && $at >= $window->until => self::Expired,
            default => self::Live,
        };
    }

    /**
     * When the state a window gives at the time changes next: the start of a window that has not
     * begun, the end of one that holds the time, or null when it never changes again.
     */
    public static function nextTransition(?TimeWindow $window, DateTimeImmutable $at): ?DateTimeImmutable
    {
        return match (self::of($window, $at)) {
            self::Scheduled => $window?->from,
            self::Live => $window?->until,
            default => null,
        };
    }

    /**
     * Whether a placement in this state, with this window, is visible at the time, as far as its
     * own state and window decide (PRD 6.6 points 5 and 9): it is neither hidden nor withdrawn, and
     * the window holds the time.
     */
    public function visibleAt(?TimeWindow $window, DateTimeImmutable $at): bool
    {
        return $this !== self::Hidden && $this !== self::Withdrawn && $window instanceof TimeWindow && $window->contains($at);
    }
}
