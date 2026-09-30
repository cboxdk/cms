<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

/**
 * The directories of a checkout that a run in the dev image keeps in Docker volumes of the
 * checkout's own instead of the bind mount (CheckoutVolume):
 *
 * - NodeModules: the host's node_modules holds the binaries npm installed for the host's platform,
 *   such as macOS; the image is Linux, so it gets its own, installed there by `npm ci`
 *   (NodeModulesStamp). A volume is also faster than the bind mount for the many small files tsc
 *   and ESLint read.
 * - Cache: `.cache`, where PHPStan, Rector and Pint keep their caches.
 * - BootstrapCache: the bootstrap cache of the Testbench application, where Laravel keeps its
 *   package and services manifests and cms:build the registry cache. The entry mirrors the host's
 *   copy into it at the start of each run, so it holds what the host holds.
 *
 * `.cache` and the bootstrap cache are volumes because the parallel workers of a run replace files
 * there with a rename while other workers read them, and on Docker Desktop's bind mount a file
 * replaced with a rename is missing for a moment to a reader in the container (measured: about one
 * read in eight while another process renames; none on the host and none in a volume). PHPStan
 * then failed to include its container, and Laravel to open bootstrap/cache/services.php.
 */
enum VolumeKind: string
{
    case NodeModules = 'node-modules';
    case Cache = 'cache';
    case BootstrapCache = 'bootstrap-cache';

    /**
     * The directory below the checkout the volume is mounted over.
     */
    public function directory(): string
    {
        return match ($this) {
            self::NodeModules => 'node_modules',
            self::Cache => '.cache',
            self::BootstrapCache => 'vendor/orchestra/testbench-core/laravel/bootstrap/cache',
        };
    }
}
