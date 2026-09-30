<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;

/**
 * The typed explanation of one resolution (PRD 5.9, GUARDRAILS 5 "Forklaringer fra dag ét"): each
 * step it took, in order, and the outcome, named by the step that stopped it. A step it did not
 * reach is null; mount is null too when the node is not a mount. cms:explain, the developer bar
 * and spans read this explanation; there is no other explain code.
 */
#[Experimental]
final readonly class PathExplanation
{
    public function __construct(
        public ResolveOutcome $outcome,
        public SiteStep $site,
        public ?RouteStep $route = null,
        public ?NodeStep $node = null,
        public ?MountStep $mount = null,
        public ?PlacementStep $placement = null,
        public ?VisibilityStep $visibility = null,
        public ?CanonicalStep $canonical = null,
    ) {}
}
