<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Composer\Autoload\ClassLoader;
use LogicException;

/**
 * Where this checkout is: the directory of the Composer root package whose autoloader loaded the
 * testkit, and that autoloader's vendor directory.
 *
 * Composer\InstalledVersions answers for the autoloader registered last, and a tool can register
 * one of its own (Rector's bundled autoloader has a root package of its own), so the root is read
 * from the installed.php of the vendor directory that finds the testkit's classes. The class
 * ClassLoader itself may come from elsewhere too: Herd's PHP, for one, prepends a phar with its own
 * Composer.
 */
#[Experimental]
final class CheckoutRoot
{
    private static ?string $root = null;

    /**
     * The real path of the checkout root.
     */
    public static function current(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        $installed = require self::vendorDirectory().'/composer/installed.php';
        $path = is_array($installed) && is_array($installed['root'] ?? null) ? ($installed['root']['install_path'] ?? null) : null;

        if (! is_string($path)) {
            throw new LogicException('The installed.php of the vendor directory that loads the testkit names no root package.');
        }

        return self::$root = TestDatabaseName::realpath($path);
    }

    /**
     * The vendor directory of the registered Composer autoloader that loads the testkit.
     */
    public static function vendorDirectory(): string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            if ($loader->findFile(self::class) !== false && is_file($vendorDir.'/autoload.php')) {
                return $vendorDir;
            }
        }

        throw new LogicException('No registered Composer autoloader loads the testkit.');
    }
}
