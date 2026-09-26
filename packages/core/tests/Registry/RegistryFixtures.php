<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scan roots over the fixtures in Fixtures/, and scratch directories for the cache.
 */
final class RegistryFixtures
{
    public const string PACKAGE = 'cboxdk/cms-registry-fixtures';

    /** @var list<string> */
    private static array $scratch = [];

    public static function root(string $fixture, string $package = self::PACKAGE): ScanRoot
    {
        return new ScanRoot($package, __DIR__.'/Fixtures/'.$fixture);
    }

    public static function builder(string $directory): BuildRegistry
    {
        return new BuildRegistry(new AttributeScanner, new RegistryCompiler, self::cache($directory));
    }

    public static function cache(string $directory): FileRegistryCache
    {
        return new FileRegistryCache($directory, new RegistryCacheCodec);
    }

    /**
     * A directory path that does not exist yet. cleanUp() removes it after the test.
     */
    public static function scratch(): string
    {
        $directory = sys_get_temp_dir().'/cms-registry-'.bin2hex(random_bytes(6));
        self::$scratch[] = $directory;

        return $directory;
    }

    public static function cleanUp(): void
    {
        foreach (self::$scratch as $directory) {
            self::remove($directory);
        }

        self::$scratch = [];
    }

    public static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }

    /**
     * The sha256 of the file of each registry, by file name.
     *
     * @return array<string, string>
     */
    public static function hashes(string $directory): array
    {
        $hashes = [];

        foreach (RegistryName::cases() as $name) {
            $hashes[$name->fileName()] = (string) hash_file('sha256', $directory.'/'.$name->fileName());
        }

        return $hashes;
    }

    /**
     * Every file in the directory, sorted, relative to it.
     *
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        $files = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * What a cache file returns.
     */
    public static function load(string $path): mixed
    {
        return require $path;
    }
}
