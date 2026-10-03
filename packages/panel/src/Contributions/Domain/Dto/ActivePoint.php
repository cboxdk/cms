<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * A point a page renders with the contributions active on it for the viewer, in the order the
 * host renders them, and its declaration, which tells the host its kind, region and multiplicity
 * (PRD 13.4).
 */
#[Experimental]
final readonly class ActivePoint
{
    /**
     * @param  non-empty-list<ActiveFill>  $fills
     */
    public function __construct(
        public PointId $point,
        public array $fills,
        public PanelPoint $declaration,
    ) {}
}
