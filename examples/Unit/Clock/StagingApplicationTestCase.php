<?php

declare(strict_types=1);

namespace Examples\Unit\Clock;

use Cbox\Cms\Contracts\Clock;
use DateInterval;
use Examples\Contract\Clock\StagingClock;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * Boots an application whose configuration replaces the Clock and nothing else. The packages load
 * through package discovery, as in an installed application.
 */
abstract class StagingApplicationTestCase extends TestCase
{
    #[Override]
    protected $enablesPackageDiscoveries = true;

    /**
     * Runs after the service providers register and before they boot, so before anything resolves
     * the Clock. The core has merged its defaults into cms.contracts by then, so the test sets the
     * one entry and the others keep their defaults, as with an application's config/cms.php.
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('cms.contracts.'.Clock::class, StagingClock::class);

        // What an application does in a service provider's register(): the container builds the
        // clock, so it gives the clock its constructor argument.
        $app->when(StagingClock::class)
            ->needs(DateInterval::class)
            ->give(static fn (): DateInterval => new DateInterval('P1D'));
    }
}
