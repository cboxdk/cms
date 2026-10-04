<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Boundary\LocalFiles;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Panel\Boundary\InstalledBundles;
use Cbox\Cms\Panel\Doctor\Domain\Dto\BundleState;
use Cbox\Cms\Panel\Doctor\Domain\Probes\AddonBundlesProbe;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Override;

/**
 * Reads each addon's bundle files from the directory the addon's provider names and compares
 * their SHA-384 with the compiled registry's, as the panel does before it serves a file.
 */
#[Internal]
final readonly class DiskAddonBundlesProbe implements AddonBundlesProbe
{
    public function __construct(
        private RegistryCache $registry,
        private BundleDirectories $directories,
    ) {}

    #[Override]
    public function bundles(): array
    {
        $registry = $this->registry->read();
        $states = [];

        foreach (InstalledBundles::unlocated($registry, $this->directories) as $namespace) {
            $states[$namespace] = new BundleState(new AddonNamespace($namespace), ['the process knows no directory for the bundle, because the addon\'s provider names none']);
        }

        foreach (InstalledBundles::read($registry, $this->directories)->bundles as $bundle) {
            $problems = [];

            foreach ($bundle->files as $file) {
                $bytes = LocalFiles::read($bundle->pathOf($file));

                if ($bytes === null) {
                    $problems[] = sprintf('the file %s is missing or unreadable', $file->path->value);
                } elseif (! BundleIntegrity::of($bytes)->equals($file->integrity)) {
                    $problems[] = sprintf('the file %s has another SHA-384 than cms:build compiled', $file->path->value);
                }
            }

            $states[$bundle->addon->value] = new BundleState($bundle->addon, $problems);
        }

        ksort($states, SORT_STRING);

        return array_values($states);
    }
}
