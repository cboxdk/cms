<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli;

use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the cli package in a Laravel application. Loaded through package discovery.
 *
 * Registers the cms:* Artisan commands.
 */
#[Internal]
final class CliServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MaintainPartitionsCommand::class,
            ]);
        }
    }
}
