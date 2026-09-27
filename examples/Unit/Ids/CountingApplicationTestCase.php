<?php

declare(strict_types=1);

namespace Examples\Unit\Ids;

use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Examples\Contract\Ids\CountingIdGenerator;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * Boots an application whose configuration replaces the IdGenerator and nothing else. The packages
 * load through package discovery, as in an installed application.
 */
abstract class CountingApplicationTestCase extends TestCase
{
    #[Override]
    protected $enablesPackageDiscoveries = true;

    /**
     * Runs after the service providers register and before they boot, so before anything resolves
     * the IdGenerator. The core has merged its defaults into cbox-cms.contracts by then, so the test sets
     * the one entry and the others keep their defaults, as with an application's config/cbox-cms.php.
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('cbox-cms.contracts.'.IdGenerator::class, CountingIdGenerator::class);

        // What an application does in a service provider's register(): the counting generator
        // wraps the core's generator, which reads the time from the configured Clock.
        $app->when(CountingIdGenerator::class)
            ->needs(IdGenerator::class)
            ->give(SystemIdGenerator::class);
    }
}
