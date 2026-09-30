<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests;

use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Bindings\Boundary\InvalidContractBinding;
use Cbox\Cms\Core\Cache\Adapter\ValkeyFragmentStore;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\IdempotencyStore\Boundary\IdempotencyConfig;
use Cbox\Cms\Core\Operations\Adapter\PackageOperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Adapter\LoggedHookOverruns;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryCommandHooks;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryWriteActions;
use Cbox\Cms\Core\Pipeline\Boundary\TypeRulesFieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\ReadModels\Adapter\PostgresReadModelStore;
use Cbox\Cms\Core\ReadModels\Boundary\RebuildConfig;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\Reads\Boundary\QueryConfig;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Seeding\Adapter\PostgresSeedReader;
use Cbox\Cms\Core\Seeding\Adapter\TransactionalSeedTargets;
use Cbox\Cms\Core\Seeding\Boundary\SeedContentHasher;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Cbox\Cms\Core\Seeding\Domain\SeedAuthorizer;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Adapter\RegistryLaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Subscriptions\Boundary\RunnerConfig;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Telemetry\Adapter\LogTelemetry;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\TypeTables\Adapter\PostgresTypeTableReader;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Operations\Contracts\Operations;
use Cbox\Operations\OperationManager;
use Cbox\Operations\OperationsServiceProvider;
use Illuminate\Config\Repository;
use ReflectionClass;
use ReflectionProperty;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(CoreServiceProvider::class)
        ->and(app()->getProviders(CoreServiceProvider::class))->toHaveCount(1);
});

it('loads the core migrations, which create the receipt tables', function (): void {
    $paths = array_map(realpath(...), app('migrator')->paths());
    $core = realpath(__DIR__.'/../database/migrations');

    expect($core)->toBeString()
        ->and($paths)->toContain($core)
        ->and(glob($core.'/*_create_receipts_tables.php'))->toHaveCount(1);
});

it('registers laravel-operations itself, with its migration, and binds the OperationRunner to it (GUARDRAILS 3, 4.2)', function (): void {
    $paths = array_map(realpath(...), app('migrator')->paths());
    $package = realpath(dirname((string) new ReflectionClass(OperationsServiceProvider::class)->getFileName(), 2).'/database/migrations');

    expect(app()->getLoadedProviders())->toHaveKey(OperationsServiceProvider::class)
        ->and(app(Operations::class))->toBeInstanceOf(OperationManager::class)
        ->and(app(OperationRunner::class))->toBeInstanceOf(PackageOperationRunner::class)
        ->and($package)->toBeString()
        ->and($paths)->toContain($package);
});

it('merges config/cbox-cms.php under cbox-cms, the only root of the configuration, and the readers use it', function (): void {
    $defaults = new Repository(['cbox-cms' => require __DIR__.'/../config/cbox-cms.php']);

    expect(array_map(basename(...), glob(__DIR__.'/../config/*.php') ?: []))->toBe(['cbox-cms.php'])
        ->and(array_keys($defaults->array('cbox-cms')))->toBe(['contracts', 'database', 'addons', 'cli', 'queries', 'idempotency', 'events', 'rebuild', 'seeding', 'fragments', 'doctor'])
        ->and(config('cbox-cms.contracts'))->toBe($defaults->get('cbox-cms.contracts'))
        ->and(config('cbox-cms.database.partitions.runway_days'))->toBe($defaults->get('cbox-cms.database.partitions.runway_days'))
        ->and(config('cbox-cms.queries.budgets'))->toBe($defaults->get('cbox-cms.queries.budgets'))
        ->and(config('cbox-cms.idempotency.wait_budget_ms'))->toBe($defaults->get('cbox-cms.idempotency.wait_budget_ms'))
        ->and(config('cbox-cms.events.runner'))->toBe($defaults->get('cbox-cms.events.runner'))
        ->and(config('cbox-cms.rebuild'))->toBe($defaults->get('cbox-cms.rebuild'))
        ->and($defaults->get('cbox-cms.rebuild'))->toBe(['service_actor' => null, 'chunk_size' => 100])
        ->and(config('cbox-cms.addons.service_actors'))->toBe($defaults->get('cbox-cms.addons.service_actors'))
        ->and(config('cbox-cms.cli.credential'))->toBeNull()
        ->and($defaults->get('cbox-cms.cli.credential'))->toBeNull()
        ->and(config()->has('cms'))->toBeFalse()
        ->and([ContractBindings::CONFIG_KEY, DoctorConfig::CONFIG_KEY, PartitionConfig::CONFIG_KEY, IdempotencyConfig::CONFIG_KEY, RunnerConfig::CONFIG_KEY, QueryConfig::CONFIG_KEY])
        ->toBe(['cbox-cms.contracts', 'cbox-cms.doctor', 'cbox-cms.database', 'cbox-cms.idempotency', 'cbox-cms.events.runner', 'cbox-cms.queries']);
});

it('binds the rebuild\'s store and its settings from the configuration', function (): void {
    config()->set('cbox-cms.rebuild.chunk_size', 7);

    expect(app(ReadModelStore::class))->toBeInstanceOf(PostgresReadModelStore::class)
        ->and(app(RebuildSettings::class)->chunkSize)->toBe(7)
        ->and(app(RebuildSettings::class)->serviceActor)->toBeNull()
        ->and(RebuildConfig::CONFIG_KEY)->toBe('cbox-cms.rebuild');
});

it('binds the event runner\'s ports and its settings from the configuration', function (): void {
    app()->instance(CompiledRegistry::class, CompiledRegistry::empty());
    config()->set('cbox-cms.events.runner.batch_size', 7);

    expect(app(SubscriptionLog::class))->toBeInstanceOf(PostgresSubscriptionLog::class)
        ->and(app(LaneSubscribers::class))->toBeInstanceOf(RegistryLaneSubscribers::class)
        ->and(app(Pacing::class))->toBeInstanceOf(SystemPacing::class)
        ->and(app(RunnerSettings::class)->batchSize)->toBe(7)
        ->and(app(RunnerSettings::class)->serviceActor)->toBeNull();
});

it('binds the command pipeline\'s ports it implements: the registry\'s write actions and the generated validators', function (): void {
    app()->instance(CompiledRegistry::class, CompiledRegistry::empty());

    expect(app(WriteActions::class))->toBeInstanceOf(RegistryWriteActions::class)
        ->and(app(FieldValidation::class))->toBeInstanceOf(TypeRulesFieldValidation::class);
});

it('binds the hooks\' ports: the registry\'s hooks, the hrtime stopwatch once per process and the log for overruns', function (): void {
    app()->instance(CompiledRegistry::class, CompiledRegistry::empty());

    expect(app(CommandHooks::class))->toBeInstanceOf(RegistryCommandHooks::class)
        ->and(app(Stopwatch::class))->toBeInstanceOf(HrtimeStopwatch::class)
        ->and(app(Stopwatch::class))->toBe(app(Stopwatch::class))
        ->and(app(HookOverruns::class))->toBeInstanceOf(LoggedHookOverruns::class)
        ->and(app(HookRunner::class))->toBeInstanceOf(HookRunner::class);
});

it('binds the FragmentStore to the Valkey store once per process, and the CdnDriver only to the class an application configures', function (): void {
    expect(app(FragmentStore::class))->toBeInstanceOf(ValkeyFragmentStore::class)
        ->and(app(FragmentStore::class))->toBe(app(FragmentStore::class))
        ->and(fn (): CdnDriver => app(CdnDriver::class))->toThrow(InvalidContractBinding::class, 'No implementation of ['.CdnDriver::class.'] is configured');

    config()->set('cbox-cms.contracts.'.CdnDriver::class, FakeCdnDriver::class);
    app()->forgetInstance(CdnDriver::class);

    expect(app(CdnDriver::class))->toBeInstanceOf(FakeCdnDriver::class);
});

it('binds the TypeTableReader to the Postgres reader once per process', function (): void {
    expect(app(TypeTableReader::class))->toBeInstanceOf(PostgresTypeTableReader::class)
        ->and(app(TypeTableReader::class))->toBe(app(TypeTableReader::class));
});

it('binds the seeder\'s ports and settings, and gives SeedDataset a pipeline with the seeder\'s authorizer and content hasher', function (): void {
    config(['cbox-cms.seeding.service_actor' => '01936f5e-8a2b-7c3d-9e4f-0000000047c1']);
    $pipeline = new ReflectionProperty(SeedDataset::class, 'pipeline')->getValue(app(SeedDataset::class));

    expect(app(SeedReader::class))->toBeInstanceOf(PostgresSeedReader::class)
        ->and(app(SeedTargets::class))->toBeInstanceOf(TransactionalSeedTargets::class)
        ->and(app(SeedSettings::class)->serviceActor?->toString())->toBe('01936f5e-8a2b-7c3d-9e4f-0000000047c1')
        ->and($pipeline)->toBeInstanceOf(CommandPipeline::class);

    if (! $pipeline instanceof CommandPipeline) {
        return;
    }

    expect(new ReflectionProperty(CommandPipeline::class, 'authorizer')->getValue($pipeline))->toBeInstanceOf(SeedAuthorizer::class)
        ->and(new ReflectionProperty(CommandPipeline::class, 'hasher')->getValue($pipeline))->toBeInstanceOf(SeedContentHasher::class)
        ->and(new ReflectionProperty(CommandPipeline::class, 'committer')->getValue($pipeline))->toBeInstanceOf(PostgresChangesetCommitter::class);
});

it('binds Telemetry to the log exporter once per process, and builds the pipelines\' telemetry with it', function (): void {
    expect(app(Telemetry::class))->toBeInstanceOf(LogTelemetry::class)
        ->and(app(Telemetry::class))->toBe(app(Telemetry::class))
        ->and(app(PipelineTelemetry::class))->toBeInstanceOf(PipelineTelemetry::class);

    config()->set('cbox-cms.contracts.'.Telemetry::class, FakeTelemetry::class);
    app()->forgetInstance(Telemetry::class);

    expect(app(Telemetry::class))->toBeInstanceOf(FakeTelemetry::class);
});
