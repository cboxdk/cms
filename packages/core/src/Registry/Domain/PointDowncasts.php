<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;

/**
 * The props of any version of a panel point, built from the props of its newest version (PRD
 * 13.4): the panel builds a point's props once, as the newest version's props class, and a
 * contribution to an older version gets them through the older class's declared downcast,
 * DowncastsFromNewest. cms:build holds every older version to having one.
 */
#[Experimental]
final readonly class PointDowncasts
{
    public function __construct(private CompiledRegistry $registry) {}

    /**
     * The props of the point $target, from the props of the newest version of its name: those
     * props themselves when $target is the newest version, else what the target's downcast builds
     * from them.
     *
     * @throws UnknownPanelPoint when the registry holds no point $target
     * @throws PointDowncastRefused when $newest are not the props of the newest version, the target
     *                              has no downcast, or the downcast builds something else than its
     *                              own props
     */
    public function props(PointId $target, object $newest): object
    {
        $point = $this->registry->panelPoint($target);

        if (! $point instanceof PanelPointEntry) {
            throw UnknownPanelPoint::notRegistered($target, array_map(static fn (PanelPointEntry $entry): PointId => $entry->id(), $this->registry->panel));
        }

        $latest = $this->newest($point);

        if (! $newest instanceof $latest->class) {
            throw PointDowncastRefused::notNewest($target, $latest, $newest);
        }

        if ($latest === $point) {
            return $newest;
        }

        $class = $point->class;

        if (! is_a($class, DowncastsFromNewest::class, true)) {
            throw PointDowncastRefused::withoutDowncast($target);
        }

        $props = $class::downcast($newest);

        if (! $props instanceof $point->class) {
            throw PointDowncastRefused::builtOther($target, $props);
        }

        return $props;
    }

    /**
     * The newest version of the point's name in the registry.
     */
    private function newest(PanelPointEntry $point): PanelPointEntry
    {
        $newest = $point;

        foreach ($this->registry->panel as $entry) {
            if ($entry->declaration->name === $point->declaration->name && $entry->declaration->version > $newest->declaration->version) {
                $newest = $entry;
            }
        }

        return $newest;
    }
}
