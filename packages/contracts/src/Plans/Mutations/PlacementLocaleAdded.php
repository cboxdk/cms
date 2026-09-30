<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A placement gets a language (PRD 5.7): its PlacementLocale with the slug below the placement's
 * node, hidden and without a window until one is set, and whether it is the canonical placement
 * of its entry in that language, which the kernel decides (invariant 14).
 */
#[Experimental]
final readonly class PlacementLocaleAdded implements Mutation
{
    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
        public Slug $slug,
        public bool $canonical,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }
}
