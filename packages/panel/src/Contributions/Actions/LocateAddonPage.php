<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonPageRef;
use Cbox\Cms\Panel\Contributions\Domain\Dto\LocatedPage;

/**
 * Finds the page an addon contributes at an address (PRD 13.4, section 3.4 of the panel
 * extension architecture): the PageContribution of the addon with the path, among the fills of
 * every page point of the compiled registry, as a LocatedPage with its id, or null when no addon contributes one
 * there or the registry cannot be read. Whether the viewer gets the page is decided afterwards,
 * by ResolveContributions, as for every contribution: enabled, in scope and with the permission
 * its scope requires.
 */
#[Experimental]
final readonly class LocateAddonPage
{
    public function __construct(
        private RegistryCache $registry,
    ) {}

    public function locate(AddonPageRef $ref): ?LocatedPage
    {
        try {
            $registry = $this->registry->read();
        } catch (RegistryCacheMissing|MalformedRegistryCache) {
            return null;
        }

        foreach ($registry->panel as $point) {
            if ($point->declaration->kind !== PointKind::Page) {
                continue;
            }

            foreach ($point->fills as $fill) {
                $page = $fill->declaration;

                if ($page instanceof PageContribution && $page->path === $ref->path && $fill->addon()->equals($ref->addon)) {
                    return new LocatedPage($fill->contribution);
                }
            }
        }

        return null;
    }
}
