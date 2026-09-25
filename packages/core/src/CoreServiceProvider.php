<?php

declare(strict_types=1);

namespace Cbox\Cms\Core;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the core package in a Laravel application. Loaded through package discovery.
 *
 * Binds each contract to the implementation configured in `cms.contracts` (GUARDRAILS 2.3).
 */
#[Internal]
final class CoreServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/cms.php', 'cms');

        $this->app->singleton(
            Clock::class,
            static fn (Application $app): Clock => $app->make(ContractBindings::class)->resolve($app, Clock::class),
        );
    }
}
