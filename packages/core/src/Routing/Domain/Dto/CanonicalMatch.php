<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;

/**
 * The canonical placement of an entry in a locale (PRD 5.7, invariant 14): the placement, its node
 * and its slug, and the site whose tree holds the node with the node's route there in the locale,
 * both null when the node has no route in its site.
 */
#[Internal]
final readonly class CanonicalMatch
{
    public function __construct(
        public PlacementId $placement,
        public NodeId $node,
        public Slug $slug,
        public ?SiteHandle $site,
        public ?string $route,
    ) {}

    /**
     * The path of the placement on its site, the node's route and the slug, or null without a route.
     */
    public function path(): ?string
    {
        return match ($this->route) {
            null => null,
            '/' => '/'.$this->slug->value,
            default => $this->route.'/'.$this->slug->value,
        };
    }
}
