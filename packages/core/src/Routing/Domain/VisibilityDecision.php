<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What decided whether the public sees a placement in a language (PRD 5.7, 6.6), the rungs of the
 * precedence that M1 reaches, strongest first. The first rung that blocks decides; Visible is the
 * last rung, live, reached only when none blocked.
 *
 * - EntryNotActive (rung 2 and PRD 5.7): the entry is not active, or the reader cannot read it.
 * - VariantWithdrawn (rung 3): the variant is taken down everywhere.
 * - PlacementWithdrawn (rung 5).
 * - NoReleasedRevision (rung 8): a type with stages has no released revision of the variant the
 *   reader can read. A type without stages shows its current state and never stops here.
 * - PlacementHidden (rung 9): the placement has no window.
 * - BeforeWindow and AfterWindow (rung 9): the window has not begun, or has ended, at the time.
 * - Visible (rung 11): live.
 *
 * Erasure (rung 1), subject restriction (4), mount exceptions (6), licences (7) and audiences (10)
 * come with the blocks that bring them.
 */
#[Experimental]
enum VisibilityDecision: string
{
    case EntryNotActive = 'entry_not_active';
    case VariantWithdrawn = 'variant_withdrawn';
    case PlacementWithdrawn = 'placement_withdrawn';
    case NoReleasedRevision = 'no_released_revision';
    case PlacementHidden = 'placement_hidden';
    case BeforeWindow = 'before_window';
    case AfterWindow = 'after_window';
    case Visible = 'visible';

    /**
     * The rung of PRD 6.6 the decision is, from 1, the strongest, to 11, live.
     */
    public function rung(): int
    {
        return match ($this) {
            self::EntryNotActive => 2,
            self::VariantWithdrawn => 3,
            self::PlacementWithdrawn => 5,
            self::NoReleasedRevision => 8,
            self::PlacementHidden, self::BeforeWindow, self::AfterWindow => 9,
            self::Visible => 11,
        };
    }

    public function visible(): bool
    {
        return $this === self::Visible;
    }
}
