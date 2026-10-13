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
 * A placement gets another slug in one language (PRD 5.9): the last segment of its URL changes,
 * and the slug it had goes to the slug history, so the URL that was out there answers 301 to the
 * current one. A rename is not a visibility change: the placement keeps its state and its window,
 * and a visible placement stays visible, so an agent may plan it.
 */
#[Experimental]
final readonly class PlacementSlugSet implements Mutation
{
    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
        public Slug $slug,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }
}
