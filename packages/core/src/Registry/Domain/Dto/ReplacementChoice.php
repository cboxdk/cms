<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * Which replacement wins one key of a replaceable panel point, as the installation chooses it in
 * cbox-cms.panel.replacements (PRD 13.4). cms:build makes the winner the one replacement of the key
 * and disables the others, and refuses a choice that names no replacement of that key, as
 * registry_panel_override_invalid.
 */
#[Experimental]
final readonly class ReplacementChoice
{
    public function __construct(
        public PointId $point,
        public string $key,
        public ContributionId $winner,
    ) {}
}
