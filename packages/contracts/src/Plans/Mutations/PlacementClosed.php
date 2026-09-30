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
 * A placement is closed in one language because its entry's content is unpublished (PRD 6.4): its
 * PlacementLocale is hidden and loses its window, so publishing the content again shows it nowhere
 * until a window is set. It is decided on the entry's home, not on the placement's node (PRD 5.10),
 * so it closes the placements of every site, also below nodes the actor's regions do not reach. It
 * hides content and makes nothing public.
 */
#[Experimental]
final readonly class PlacementClosed implements Mutation
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
