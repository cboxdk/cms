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
 * A placement is withdrawn in one language because its entry's variant of that language is deleted
 * (PRD 10.3): its PlacementLocale is withdrawn, loses its window and its canonical flag, and its
 * URL answers 410, gone (PRD 8.10). Withdrawn is sticky: only a reinstatement changes it
 * (invariant 7). It is decided on the entry's home in the locale, not on the placement's node
 * (PRD 5.10), so it withdraws the placements of every site, also below nodes the actor's regions
 * do not reach. It hides content and makes nothing public.
 */
#[Experimental]
final readonly class PlacementWithdrawn implements Mutation
{
    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->placement;
    }
}
