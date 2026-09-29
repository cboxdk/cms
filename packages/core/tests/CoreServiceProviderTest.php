<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests;

use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\IdempotencyStore\Boundary\IdempotencyConfig;
use Cbox\Cms\Core\Operations\Adapter\PackageOperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Adapter\LoggedHookOverruns;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryCommandHooks;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryWriteActions;
use Cbox\Cms\Core\Pipeline\Boundary\TypeRulesFieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Subscriptions\Adapter\PostgresSubscriptionLog;
use Cbox\Cms\Core\Subscriptions\Adapter\RegistryLaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Subscriptions\Boundary\RunnerConfig;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Operations\Contracts\Operations;
use Cbox\Operations\OperationManager;
use Cbox\Operations\OperationsServiceProvider;
use Illuminate\Config\Repository;
use ReflectionClass;

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
        ->and(array_keys($defaults->array('cbox-cms')))->toBe(['contracts', 'database', 'idempotency', 'events', 'doctor'])
        ->and(config('cbox-cms.contracts'))->toBe($defaults->get('cbox-cms.contracts'))
        ->and(config('cbox-cms.database.partitions.runway_days'))->toBe($defaults->get('cbox-cms.database.partitions.runway_days'))
        ->and(config('cbox-cms.idempotency.wait_budget_ms'))->toBe($defaults->get('cbox-cms.idempotency.wait_budget_ms'))
        ->and(config('cbox-cms.events.runner'))->toBe($defaults->get('cbox-cms.events.runner'))
        ->and(config()->has('cms'))->toBeFalse()
        ->and([ContractBindings::CONFIG_KEY, DoctorConfig::CONFIG_KEY, PartitionConfig::CONFIG_KEY, IdempotencyConfig::CONFIG_KEY, RunnerConfig::CONFIG_KEY])
        ->toBe(['cbox-cms.contracts', 'cbox-cms.doctor', 'cbox-cms.database', 'cbox-cms.idempotency', 'cbox-cms.events.runner']);
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
