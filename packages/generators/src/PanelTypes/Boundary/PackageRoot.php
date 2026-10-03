<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * Where Composer installed a package, the root of the addon whose panel types cms:panel:types
 * writes: its directory below vendor/, followed to the real directory of a path repository, or the
 * root of the repository when the addon is the root package, as in its own repository.
 */
#[Internal]
final readonly class PackageRoot
{
    /**
     * The absolute directory of the package, or null when Composer did not install it.
     */
    public static function of(string $package): ?string
    {
        try {
            $path = InstalledVersions::getInstallPath($package);
        } catch (OutOfBoundsException) {
            return null;
        }

        $real = $path === null ? false : realpath($path);

        return $real === false || ! is_dir($real) ? null : $real;
    }
}
