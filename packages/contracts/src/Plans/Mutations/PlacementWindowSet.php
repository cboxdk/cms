<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * The window in which a placement is live in one language is set: live_from and live_until of
 * its PlacementLocale (PRD 5.7). It replaces the window the placement had in that language.
 */
#[Experimental]
final readonly class PlacementWindowSet implements Mutation
{
    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
        public TimeWindow $window,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }
}
