<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli;

use Cbox\Cms\Cli\Console\AccessBootstrapCommand;
use Cbox\Cms\Cli\Console\ActionsCommand;
use Cbox\Cms\Cli\Console\BuildCommand;
use Cbox\Cms\Cli\Console\DoctorCommand;
use Cbox\Cms\Cli\Console\ExplainCommand;
use Cbox\Cms\Cli\Console\HooksCommand;
use Cbox\Cms\Cli\Console\InstallCommand;
use Cbox\Cms\Cli\Console\ListParkedCommand;
use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Cli\Console\PanelFillsCommand;
use Cbox\Cms\Cli\Console\PanelPointsCommand;
use Cbox\Cms\Cli\Console\RebuildTypeTableCommand;
use Cbox\Cms\Cli\Console\ReleaseParkedCommand;
use Cbox\Cms\Cli\Console\RunCommand;
use Cbox\Cms\Cli\Console\RunEventsCommand;
use Cbox\Cms\Cli\Console\SeedScaleCommand;
use Cbox\Cms\Cli\Console\SitesSyncCommand;
use Cbox\Cms\Cli\Domain\CliActions;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the cli package in a Laravel application. Loaded through package discovery.
 *
 * Registers the cms:* Artisan commands, binds the writes the CLI surface runs, read once per
 * process from the compiled registry when cms:run first asks for them, and declares the package's
 * classes as a scan root for cms:build (PRD 13.2).
 */
#[Internal]
final class CliServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            CliActions::class,
            static fn (Application $app): CliActions => new CliActions($app->make(CompiledRegistry::class)),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AccessBootstrapCommand::class,
                ActionsCommand::class,
                BuildCommand::class,
                DoctorCommand::class,
                ExplainCommand::class,
                HooksCommand::class,
                InstallCommand::class,
                ListParkedCommand::class,
                MaintainPartitionsCommand::class,
                PanelFillsCommand::class,
                PanelPointsCommand::class,
                RebuildTypeTableCommand::class,
                ReleaseParkedCommand::class,
                RunCommand::class,
                RunEventsCommand::class,
                SeedScaleCommand::class,
                SitesSyncCommand::class,
            ]);
        }
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
