<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;

/**
 * The contributions active on a panel page for one viewer and request (PRD 13.4), point by point
 * in the order the page lists its points: what the page sends as cms.contributions, and the fills
 * whose data it sends as the deferred prop of their addon.
 */
#[Experimental]
final readonly class ActiveContributions
{
    /**
     * @param  list<ActivePoint>  $points
     */
    public function __construct(
        public PageName $page,
        public array $points = [],
    ) {}

    /**
     * The fills that read data, by the namespace of their addon, each namespace once and sorted,
     * the fills in render order.
     *
     * @return array<string, non-empty-list<ActiveFill>>
     */
    public function withData(): array
    {
        $byAddon = [];

        foreach ($this->points as $point) {
            foreach ($point->fills as $fill) {
                if ($fill->data() instanceof CommandRef) {
                    $byAddon[$fill->fill->addon()->value][] = $fill;
                }
            }
        }

        ksort($byAddon, SORT_STRING);

        return $byAddon;
    }
}
