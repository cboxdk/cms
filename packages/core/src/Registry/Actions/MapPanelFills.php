<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFillsRequest;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;

/**
 * The contributions to a panel point as cms:panel:fills lists them (PRD 13.2, 13.4): the point's
 * entry of the compiled registry, whose fills are in the order the host renders them, priority with
 * the lowest first, then the addon's namespace, then the contribution's id.
 */
#[Experimental]
final readonly class MapPanelFills
{
    public function __construct(private RegistryCache $cache) {}

    /**
     * @throws UnknownPanelPoint
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function map(PanelFillsRequest $request): PanelPointEntry
    {
        $registry = $this->cache->read();

        return $registry->panelPoint($request->point) ?? throw UnknownPanelPoint::notRegistered(
            $request->point,
            array_map(static fn (PanelPointEntry $point): PointId => $point->id(), $registry->panel),
        );
    }
}
