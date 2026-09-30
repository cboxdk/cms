<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Sets the window in which a placement is live in one locale (PRD 5.7, 6.4), version 1 of
 * placement.set_window: the placement, the version of it the caller saw, the locale and the
 * window, live_from and live_until, either of them open; a null window hides the placement in the
 * locale.
 *
 * The kernel derives the visibility state from the window when it commits: hidden, scheduled, live
 * or expired, and the time of the next transition. A withdrawn placement keeps its state until it
 * is reinstated (invariant 7). The command is decided on the placement's node (PRD 5.10), and an
 * agent or a token may not open a window, now or later (invariant 18). The kernel keeps one
 * canonical placement of the entry in the locale, a visible one when one is visible
 * (invariant 14).
 */
#[CommandName('placement.set_window', version: 1)]
#[Experimental]
final readonly class SetPlacementWindow implements ExpectsVersions
{
    public function __construct(
        public PlacementId $placement,
        public AggregateVersion $version,
        public Locale $locale,
        public ?TimeWindow $window,
    ) {}

    /**
     * The placement, at the version the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->placement, $this->version));
    }
}
