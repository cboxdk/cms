<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Tests\Support\Registry\InstallationRegistry;

/**
 * The workbench's registry for the commands that read it, compiled as cms:build compiles it, from
 * the scan roots, addon manifests and contract schemas of the installation's providers
 * (InstallationRegistry): the core's actions and contributions and the fixture addon's hooks. bind() puts it in a FakeRegistryCache the application reads, so a test
 * sees the installation as it is, whatever the cache on disk holds.
 */
final class WorkbenchRegistry
{
    public static function compile(): CompiledRegistry
    {
        return InstallationRegistry::compile(app());
    }

    public static function bind(): CompiledRegistry
    {
        $registry = self::compile();
        $cache = new FakeRegistryCache;
        $cache->write($registry);
        app()->instance(RegistryCache::class, $cache);
        app()->instance(CompiledRegistry::class, $registry);

        return $registry;
    }

    /**
     * A registry cache that cms:build has not written, or one that cannot be read.
     */
    public static function unreadable(bool $damaged): void
    {
        $cache = new FakeRegistryCache;

        if ($damaged) {
            $cache->write(CompiledRegistry::empty());
            $cache->damage();
        }

        app()->instance(RegistryCache::class, $cache);
        app()->forgetInstance(CompiledRegistry::class);
    }
}
