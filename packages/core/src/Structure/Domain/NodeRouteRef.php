<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Override;

/**
 * A route of a site in one language (PRD 5.9), as an aggregate a command reads so two commands that
 * claim one route commit one after the other: it exists, at version 1, when a node has the route,
 * and is absent otherwise. node.set_route reads it as absent, and the commit, which locks an
 * aggregate read as absent with an advisory lock first, finds it taken when another command took
 * the route meanwhile, which is version_conflict instead of a unique violation.
 */
#[Internal]
final readonly class NodeRouteRef implements AggregateRef
{
    public const string KIND = 'node_route';

    public function __construct(
        public SiteId $site,
        public Locale $locale,
        public RequestPath $route,
    ) {}

    /**
     * "node_route:" and the site, the locale and the route, none of which holds a colon but the
     * route, whose segments hold no white space and whose last part is therefore unambiguous.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return self::KIND.':'.$this->site->toString().':'.$this->locale->value.':'.$this->route->value;
    }
}
