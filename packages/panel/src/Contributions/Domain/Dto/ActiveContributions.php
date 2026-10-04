<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PageName;

/**
 * The contributions active on a panel page for one viewer and request (PRD 13.4), point by point
 * in the order the page lists its points: what the page sends as cms.contributions, and the fills
 * whose data it sends as the deferred prop of their addon; the registration the panel's host holds
 * the code of each addon among them to (Registrations); and whether the viewer sees the detail of a
 * contribution that failed, which a viewer whose classification access is internal or above does.
 */
#[Experimental]
final readonly class ActiveContributions
{
    /**
     * @param  list<ActivePoint>  $points
     * @param  list<AddonRegistration>  $registrations  of the addons whose active contributions run code, sorted by namespace
     */
    public function __construct(
        public PageName $page,
        public array $points = [],
        public array $registrations = [],
        public bool $details = false,
    ) {}

    /**
     * The active page of an addon with the id, or null when the viewer does not get it: a page
     * is active when it is enabled, in scope and the viewer holds the permission its scope
     * requires, as every other contribution.
     */
    public function page(ContributionId $id): ?ActiveFill
    {
        foreach ($this->pages() as $page) {
            if ($page->fill->contribution->equals($id)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The addons' pages the viewer may open, in render order.
     *
     * @return list<ActiveFill>
     */
    public function pages(): array
    {
        $pages = [];

        foreach ($this->points as $point) {
            foreach ($point->fills as $fill) {
                if ($fill->fill->declaration instanceof PageContribution) {
                    $pages[] = $fill;
                }
            }
        }

        return $pages;
    }

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
