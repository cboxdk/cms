<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;

/**
 * A registry cache and vendor manifest in a temporary directory, so a test can age them without
 * touching the application's own.
 */
final class RegistryScratch
{
    public static ?string $directory = null;

    public static function create(): string
    {
        $directory = sys_get_temp_dir().'/cms-doctor-'.bin2hex(random_bytes(6));
        mkdir($directory.'/cache', 0o775, true);
        mkdir($directory.'/vendor/composer', 0o775, true);
        file_put_contents($directory.'/vendor/composer/installed.json', '{"packages":[]}');
        self::$directory = $directory;

        app()->instance(RegistryCache::class, new FileRegistryCache($directory.'/cache', new RegistryCacheCodec));
        config(['cms.doctor.vendor_manifest' => $directory.'/vendor/composer/installed.json']);

        return $directory;
    }
}
