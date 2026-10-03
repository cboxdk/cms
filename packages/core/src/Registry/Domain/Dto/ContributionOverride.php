<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * What the installation sets for one contribution to one panel point in
 * cbox-cms.panel.contributions (PRD 13.4): another priority, or null to keep the addon's, and
 * whether it is enabled, or null to keep it enabled. cms:build compiles it into the point's fills
 * and refuses one that names no contribution of the point, as registry_panel_override_invalid.
 */
#[Experimental]
final readonly class ContributionOverride
{
    public function __construct(
        public PointId $point,
        public ContributionId $contribution,
        public ?int $priority = null,
        public ?bool $enabled = null,
    ) {}
}
