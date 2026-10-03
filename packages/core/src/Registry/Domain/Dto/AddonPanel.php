<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * An addon's panel contributions in the registry, beyond the fills of each point (PRD 13.4): the
 * panel API version it needs, the experimental points it accepts, sorted, and its checked bundle,
 * or null when none of its contributions runs code.
 */
#[Experimental]
final readonly class AddonPanel
{
    /**
     * @param  list<PointId>  $acceptsExperimental
     */
    public function __construct(
        public PanelApiVersion $sdk,
        public array $acceptsExperimental,
        public ?CompiledBundle $bundle,
    ) {}
}
