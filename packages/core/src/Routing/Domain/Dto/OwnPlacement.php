<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Core\Placements\Domain\Visibility;

/**
 * A site's own placement of an entry in a locale (PRD 5.8): the placement in its released stage
 * below a node of the site that has a route in the locale, with the route, the slug, the stored
 * visibility and the window, so the resolution decides at its time whether the placement shows
 * and builds its URL from the site's origin.
 */
#[Internal]
final readonly class OwnPlacement
{
    public function __construct(
        public PlacementId $placement,
        public NodeId $node,
        public string $route,
        public Slug $slug,
        public Visibility $visibility,
        public ?TimeWindow $window,
    ) {}

    /**
     * The path of the placement on its site: the node's route and the slug.
     */
    public function path(): string
    {
        return $this->route === '/' ? '/'.$this->slug->value : $this->route.'/'.$this->slug->value;
    }
}
