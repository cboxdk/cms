<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A placement becomes, or stops being, the canonical placement of its entry in one language
 * (PRD 5.7, invariant 14): the one every other placement of the entry names with rel=canonical.
 * The kernel sets it; a plan that moves it clears the old placement before it sets the new one.
 */
#[Experimental]
final readonly class PlacementCanonicalSet implements Mutation
{
    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
        public bool $canonical,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }
}
