<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonPageRef;

/**
 * Reads the address of an addon's page, `<prefix>/x/{namespace}/{path}` (PRD 13.4): the namespace
 * into an AddonNamespace and the path as a PageContribution declares one, or null when the
 * address names no addon or no path a page can have, which the panel answers as a path it does
 * not have.
 */
#[Internal]
final readonly class AddonPagePath
{
    private function __construct() {}

    public static function read(string $namespace, string $path): ?AddonPageRef
    {
        if (strlen($path) > PageContribution::PATH_MAX_LENGTH || preg_match(PageContribution::PATH_PATTERN, $path) !== 1) {
            return null;
        }

        try {
            return new AddonPageRef(new AddonNamespace($namespace), $path);
        } catch (InvalidAddonManifest) {
            return null;
        }
    }
}
