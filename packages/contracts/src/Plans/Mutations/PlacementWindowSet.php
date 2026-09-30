<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\ChangesPublicVisibility;
use Override;

/**
 * The window in which a placement is live in one language is set: live_from and live_until of
 * its PlacementLocale (PRD 5.7). It replaces the window the placement had in that language. A null
 * window hides the placement in that language. A window, now or later, makes it public, which an
 * agent or a token may not do (invariant 18).
 */
#[Experimental]
final readonly class PlacementWindowSet implements ChangesPublicVisibility
{
    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
        public ?TimeWindow $window,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }

    /**
     * A window makes the placement public from its start; no window hides it.
     */
    #[Override]
    public function makesPublic(): bool
    {
        return $this->window instanceof TimeWindow;
    }
}
