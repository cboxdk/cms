<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli;

use Cbox\Cms\Cli\Console\BuildCommand;
use Cbox\Cms\Cli\Console\DoctorCommand;
use Cbox\Cms\Cli\Console\ListParkedCommand;
use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Cli\Console\ReleaseParkedCommand;
use Cbox\Cms\Cli\Console\RunEventsCommand;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the cli package in a Laravel application. Loaded through package discovery.
 *
 * Registers the cms:* Artisan commands, and declares the package's classes as a scan root for
 * cms:build (PRD 13.2).
 */
#[Internal]
final class CliServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildCommand::class,
                DoctorCommand::class,
                ListParkedCommand::class,
                MaintainPartitionsCommand::class,
                ReleaseParkedCommand::class,
                RunEventsCommand::class,
            ]);
        }
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
