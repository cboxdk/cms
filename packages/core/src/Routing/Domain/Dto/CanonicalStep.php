<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\PlacementId;

/**
 * The canonical URL (PRD 5.7, 5.9): the canonical placement of the entry in the locale, or null
 * when the reader can read none; its URL, the origin of its site with the node's route and the
 * slug, or null when its node has no route or its site is not configured; and whether the path
 * resolved is that placement on its own site, false for any other placement and for a mount, whose
 * source stays canonical.
 */
#[Experimental]
final readonly class CanonicalStep
{
    public function __construct(
        public ?PlacementId $placement,
        public ?string $url,
        public bool $here,
    ) {}
}
