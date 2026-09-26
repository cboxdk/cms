<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Providers;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Illuminate\Support\ServiceProvider;

/**
 * An addon-like provider that declares one fixture directory as its scan root. The directory is
 * chosen with FixtureRootProvider::$fixture before the provider is registered.
 */
final class FixtureRootProvider extends ServiceProvider implements DeclaresScanRoots
{
    public static string $fixture = 'Valid';

    public function scanRoots(): array
    {
        return [RegistryFixtures::root(self::$fixture)];
    }
}
