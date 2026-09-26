<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\ContributedFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\NoContributedFieldTypes;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the generators package in a Laravel application. Loaded through package discovery.
 *
 * Merges the defaults for `cms.generators`, wires cms:generate (PRD 11.12) to the blueprint reader
 * that validates against the installed blueprint schema v1, the addon field types it accepts, none
 * until the registry of schema contributions registers some (PRD 13.3), the M0 generators and the
 * filesystem, registers the command, and declares the package's classes as a scan root for
 * cms:build (PRD 13.2).
 */
#[Internal]
final class GeneratorsServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms-generators';

    #[Override]
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/generators.php', GeneratorConfig::KEY);

        $this->app->bind(BlueprintSource::class, YamlBlueprintSource::class);
        $this->app->bind(ContributedFieldTypes::class, NoContributedFieldTypes::class);
        $this->app->bind(GeneratedOutput::class, FilesystemGeneratedOutput::class);

        // The M0 links of the type chain. Their order does not matter: the runner sorts the output.
        $this->app->bind(
            GeneratorRunner::class,
            static fn (): GeneratorRunner => new GeneratorRunner([
                new PhpTypeHandleEnum,
                new TypeScriptTypeHandles,
            ]),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateCommand::class]);
        }
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
