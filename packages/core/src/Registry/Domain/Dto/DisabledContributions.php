<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;

/**
 * The activation state of the panel's contributions (PRD 13.5, 13.4): the addons whose whole
 * panel UI is disabled, and single contributions that are, as cbox-cms.panel.disabled sets them.
 * The panel reads it at each request, so incident response disables a contribution without a
 * rebuild.
 */
#[Experimental]
final readonly class DisabledContributions
{
    /**
     * @param  list<AddonNamespace>  $addons
     * @param  list<ContributionId>  $contributions
     */
    public function __construct(
        public array $addons = [],
        public array $contributions = [],
    ) {}

    public function disables(PanelFill $fill): bool
    {
        return array_any($this->addons, static fn (AddonNamespace $addon): bool => $addon->equals($fill->addon()))
            || array_any($this->contributions, static fn (ContributionId $id): bool => $id->equals($fill->contribution));
    }

    /**
     * The point with every fill this state disables disabled, by the activation state. A fill the
     * installation already disabled keeps its source.
     */
    public function apply(PanelPointEntry $point): PanelPointEntry
    {
        $fills = array_map(
            fn (PanelFill $fill): PanelFill => $fill->enabled && $this->disables($fill) ? $fill->deactivated() : $fill,
            $point->fills,
        );

        return new PanelPointEntry($point->declaration, $point->class, $point->package, $point->stability, $fills);
    }

    /**
     * Whether nothing is disabled.
     */
    public function isEmpty(): bool
    {
        return $this->addons === [] && $this->contributions === [];
    }
}
