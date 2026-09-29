<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Cli\Console\SchemaEditorCommand;
use Cbox\Cms\Generators\Editor\Adapter\FilesystemSchemaFiles;
use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptContracts;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Protocol\Boundary\KernelContracts;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the generators package in a Laravel application. Loaded through package discovery.
 *
 * Merges the defaults for `cbox-cms.generators`, wires cms:generate (PRD 11.12) to the blueprint reader
 * that validates against the installed blueprint schema v1, to the registry of field types that
 * the reader resolves every field's type in, with the core's own field types registered through
 * CoreFieldTypes like any contributor's (GUARDRAILS 2.4), to the generators and to the
 * filesystem, wires cms:schema:editor (blueprint decision 3) to the blueprint files on the
 * filesystem, registers both commands, and declares the package's classes as a scan root for
 * cms:build (PRD 13.2).
 */
#[Internal]
final class GeneratorsServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    #[Override]
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/generators.php', GeneratorConfig::KEY);

        $this->app->bind(BlueprintSource::class, YamlBlueprintSource::class);
        $this->app->singleton(FieldTypeRegistry::class, static fn (): FieldTypeRegistry => new FieldTypeRegistry(new CoreFieldTypes));
        $this->app->bind(GeneratedOutput::class, FilesystemGeneratedOutput::class);
        $this->app->bind(SchemaFiles::class, FilesystemSchemaFiles::class);

        // The links of the type chain. Their order does not matter: the runner sorts the output.
        $this->app->bind(
            GeneratorRunner::class,
            static fn (): GeneratorRunner => new GeneratorRunner([
                new PhpRecordDtos,
                new PhpTypeHandleEnum,
                new PhpRecords,
                new PhpTypeCatalog(ServiceProvider::class),
                new PhpTypeValidators,
                new TypeScriptTypeHandles,
                new TypeScriptContracts(new TypeScriptRuntime()->source(...), new KernelContracts()->read(...)),
            ]),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateCommand::class, SchemaEditorCommand::class]);
        }
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
