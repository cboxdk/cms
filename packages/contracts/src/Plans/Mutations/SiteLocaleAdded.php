<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A site publishes in one more locale (PRD 5.9, 11.14): the site, its root node and the locale.
 * The locale gets its row in the site's locales, or its retired row back, and the route `/` to the
 * root node in the locale when the site has none there yet. The aggregate is the site, whose
 * version moves.
 */
#[Experimental]
final readonly class SiteLocaleAdded implements Mutation
{
    public function __construct(
        public SiteId $site,
        public NodeId $root,
        public Locale $locale,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->site;
    }
}
