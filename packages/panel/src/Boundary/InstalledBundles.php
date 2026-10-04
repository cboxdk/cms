<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Panel\Domain\BundleHash;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Cbox\Cms\Panel\Domain\Dto\ServedBundle;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;

/**
 * The bundles the panel serves (PRD 13.4): each addon of the compiled registry with a bundle whose
 * directory this process's providers name. The registry decides the files and their hashes; the
 * directory is where the bytes are read from. An addon in the registry whose provider names no
 * directory in this process is left out, so none of its files is served, and cms:doctor's
 * panel.addons says so.
 */
#[Internal]
final readonly class InstalledBundles
{
    private function __construct() {}

    public static function read(CompiledRegistry $registry, BundleDirectories $directories): ServedBundles
    {
        $bundles = [];

        foreach ($registry->addons as $addon) {
            $bundle = $addon->panel?->bundle;
            $directory = $directories->of($addon->namespace);

            if (! $bundle instanceof CompiledBundle || $directory === null) {
                continue;
            }

            $bundles[] = new ServedBundle($addon->namespace, $directory, BundleHash::of($bundle), $bundle->entry, $bundle->files);
        }

        return new ServedBundles($bundles);
    }

    /**
     * The addons of the registry with a bundle whose directory this process does not know, by
     * namespace, for cms:doctor.
     *
     * @return list<string>
     */
    public static function unlocated(CompiledRegistry $registry, BundleDirectories $directories): array
    {
        $missing = [];

        foreach ($registry->addons as $addon) {
            if ($addon->panel?->bundle instanceof CompiledBundle && $directories->of($addon->namespace) === null) {
                $missing[] = $addon->namespace->value;
            }
        }

        sort($missing, SORT_STRING);

        return $missing;
    }
}
