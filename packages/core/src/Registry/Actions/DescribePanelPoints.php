<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointsRequest;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;

/**
 * The panel points of the compiled registry as cms:panel:points lists them (PRD 13.2, 13.4), in the
 * registry's order, by name and then version: all of them, the one with an id, or those with a
 * name or rendered by a page of that name. A selection that matches no point is UnknownPanelPoint;
 * the whole list of a registry without points is empty.
 */
#[Experimental]
final readonly class DescribePanelPoints
{
    public function __construct(private RegistryCache $cache) {}

    /**
     * @return list<PanelPointEntry>
     *
     * @throws UnknownPanelPoint
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function describe(PanelPointsRequest $request): array
    {
        $points = $this->cache->read()->panel;
        $point = $request->point;
        $name = $request->name;

        if ($point instanceof PointId) {
            $selected = array_values(array_filter($points, static fn (PanelPointEntry $entry): bool => $entry->id()->equals($point)));

            return $selected !== [] ? $selected : throw UnknownPanelPoint::notRegistered($point, $this->ids($points));
        }

        if ($name instanceof PageName) {
            $selected = array_values(array_filter(
                $points,
                static fn (PanelPointEntry $entry): bool => $entry->page()->equals($name) || $entry->declaration->name === $name->value,
            ));

            return $selected !== [] ? $selected : throw UnknownPanelPoint::noneOn($name, $this->ids($points));
        }

        return $points;
    }

    /**
     * @param  list<PanelPointEntry>  $points
     * @return list<PointId>
     */
    private function ids(array $points): array
    {
        return array_map(static fn (PanelPointEntry $entry): PointId => $entry->id(), $points);
    }
}
