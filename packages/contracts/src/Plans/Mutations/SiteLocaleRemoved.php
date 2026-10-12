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
 * A site no longer publishes in a locale (PRD 5.9, 11.14): the site, its root node and the locale.
 * The locale's row is retired, not removed, so the routes in the locale stay with it and come back
 * when the locale is added again; the public resolves nothing in the locale from the commit on.
 * The aggregate is the site, whose version moves.
 */
#[Experimental]
final readonly class SiteLocaleRemoved implements Mutation
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
