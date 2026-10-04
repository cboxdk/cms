<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Checks\InvalidConfigurationCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Doctor\Domain\InvalidDoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\OrderedDoctorChecks;
use Cbox\Cms\Core\Tests\Doctor\Fakes\AddonReadyCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\AddonToolCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\BlockingOnReadinessCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\InvalidIdCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\RepeatedIdCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\UnbuildableCheck;
use Cbox\Cms\Core\Tests\Doctor\Fakes\UndecidedBlockingCheck;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;
use Illuminate\Config\Repository;
use stdClass;

/*
 * The settings under cbox-cms.doctor, their defaults, and the checks the core wires from them.
 */

it('reads the defaults of the core package', function (): void {
    $settings = ProcessEnvironment::during(
        ['CBOX_CMS_MAINTENANCE_PROCESS' => null],
        static fn (): DoctorSettings => DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => require __DIR__.'/../../config/cbox-cms.php']), '/app'),
    );

    expect($settings->connection)->toBe('pgsql')
        ->and($settings->ownerConnection)->toBe('pgsql_owner')
        ->and($settings->ownerRole)->toBeNull()
        ->and($settings->maintenanceProcess)->toBeFalse()
        ->and($settings->redisConnection)->toBe('default')
        ->and($settings->connectTimeoutSeconds)->toBe(3)
        ->and($settings->runwayDays)->toBe(7)
        ->and($settings->vendorManifest)->toBe('/app/vendor/composer/installed.json')
        ->and($settings->projectPath)->toBe('/app')
        ->and($settings->nodeMinimum)->toBe('22.13.0');
});

it('falls back to its own defaults for the settings cbox-cms.doctor leaves out', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner']]]), '/srv/app');

    expect($settings->redisConnection)->toBe('default')
        ->and($settings->connectTimeoutSeconds)->toBe(3)
        ->and($settings->runwayDays)->toBe(7)
        ->and($settings->vendorManifest)->toBe('/srv/app/vendor/composer/installed.json')
        ->and($settings->projectPath)->toBe('/srv/app')
        ->and($settings->nodeMinimum)->toBe('22.13.0');
});

it('refuses an empty connection name', function (string $key): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => [$key => '']]]);

    expect(static fn (): DoctorSettings => DoctorConfig::read($config, '/srv/app'))
        ->toThrow(InvalidDoctorConfig::class, "cbox-cms.doctor.{$key}");
})->with(['connection', 'owner_connection', 'redis_connection']);

it('points the workbench at the monorepo\'s vendor manifest and node_modules', function (): void {
    $settings = app(DoctorSettings::class);
    $root = dirname(__DIR__, 4);

    expect($settings->vendorManifest)->toBe($root.'/vendor/composer/installed.json')
        ->and($settings->projectPath)->toBe($root)
        ->and(is_file($settings->vendorManifest))->toBeTrue();
});

it('takes the connection and paths the application sets', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['doctor' => [
        'connection' => 'pgsql_app',
        'owner_connection' => 'pgsql_migrations',
        'redis_connection' => 'cache',
        'connect_timeout_seconds' => 1,
        'partition_runway_days' => 3,
        'vendor_manifest' => '/srv/vendor/composer/installed.json',
        'project_path' => '/srv',
        'node_minimum' => '24.0.0',
    ]]]), '/app');

    expect([$settings->connection, $settings->ownerConnection, $settings->redisConnection, $settings->connectTimeoutSeconds, $settings->runwayDays, $settings->vendorManifest, $settings->projectPath, $settings->nodeMinimum])
        ->toBe(['pgsql_app', 'pgsql_migrations', 'cache', 1, 3, '/srv/vendor/composer/installed.json', '/srv', '24.0.0']);
});

it('needs one empty partition ahead of a sequence by default and takes the number the application sets', function (): void {
    $config = ['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner']]];

    expect(DoctorConfig::read(new Repository($config), '/app')->runwayPartitions)->toBe(1)
        ->and(DoctorConfig::read(new Repository(array_replace_recursive($config, ['cbox-cms' => ['doctor' => ['partition_runway_partitions' => 3]]])), '/app')->runwayPartitions)->toBe(3)
        ->and(fn (): DoctorSettings => DoctorConfig::read(new Repository(array_replace_recursive($config, ['cbox-cms' => ['doctor' => ['partition_runway_partitions' => 0]]])), '/app'))
        ->toThrow(InvalidDoctorConfig::class, 'The setting cbox-cms.doctor.partition_runway_partitions must be a whole number of at least 1; it is 0.');
});

it('takes the owner connection from cbox-cms.database.owner_connection when cbox-cms.doctor has none', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_ddl']]]), '/app');

    expect($settings->ownerConnection)->toBe('pgsql_ddl');
});

it('refuses a missing owner connection, as when cbox-cms.database.owner_connection is null', function (): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => null]]]);

    expect(fn (): DoctorSettings => DoctorConfig::read($config, '/app'))
        ->toThrow(InvalidDoctorConfig::class, 'The setting cbox-cms.doctor.owner_connection must be a connection name; it is null.');
});

it('refuses invalid settings with the key and the value', function (string $key, mixed $value, string $message): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => [$key => $value]]]);

    expect(fn (): DoctorSettings => DoctorConfig::read($config, '/app'))->toThrow(InvalidDoctorConfig::class, $message);
})->with([
    ['connection', 7, 'The setting cbox-cms.doctor.connection must be a connection name; it is 7.'],
    ['owner_connection', false, 'The setting cbox-cms.doctor.owner_connection must be a connection name; it is false.'],
    ['redis_connection', '', "cbox-cms.doctor.redis_connection must be a connection name; it is ''."],
    ['connect_timeout_seconds', 0, 'cbox-cms.doctor.connect_timeout_seconds must be a whole number of at least 1; it is 0.'],
    ['partition_runway_days', '7', "cbox-cms.doctor.partition_runway_days must be a whole number of at least 1; it is '7'."],
    ['vendor_manifest', ['x'], 'cbox-cms.doctor.vendor_manifest must be a path, or null for the default; it is array.'],
    ['node_minimum', '22', "cbox-cms.doctor.node_minimum must be a version such as \"22.13.0\"; it is '22'."],
    ['owner_role', '', "cbox-cms.doctor.owner_role must be a role name, or null for the username of the owner connection; it is ''."],
    ['owner_role', 5, 'cbox-cms.doctor.owner_role must be a role name, or null for the username of the owner connection; it is 5.'],
]);

it('reads whether this is the maintenance process from CBOX_CMS_MAINTENANCE_PROCESS in its environment, never from the configuration', function (?string $variable, bool $declared): void {
    // The web, queue and maintenance processes may share one configuration cache, so a key in it
    // would declare all of them; the old key cbox-cms.doctor.maintenance_process declares nothing.
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => ['maintenance_process' => true]]]);

    $settings = ProcessEnvironment::during(
        ['CBOX_CMS_MAINTENANCE_PROCESS' => $variable],
        static fn (): DoctorSettings => DoctorConfig::read($config, '/app'),
    );

    expect($settings->maintenanceProcess)->toBe($declared);
})->with([
    'unset' => [null, false],
    'empty' => ['', false],
    'false' => ['false', false],
    'true' => ['true', true],
    'true in parentheses, as Laravel reads it' => ['(true)', true],
    '1' => ['1', true],
    '0' => ['0', false],
]);

it('refuses a CBOX_CMS_MAINTENANCE_PROCESS that is not true, false, 1 or 0', function (string $variable): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner']]]);

    expect(static fn (): DoctorSettings => ProcessEnvironment::during(
        ['CBOX_CMS_MAINTENANCE_PROCESS' => $variable],
        static fn (): DoctorSettings => DoctorConfig::read($config, '/app'),
    ))->toThrow(InvalidDoctorConfig::class, sprintf("The environment variable CBOX_CMS_MAINTENANCE_PROCESS must be true, false, 1 or 0; it is '%s'.", $variable));
})->with(['yes', 'on', 'maintenance']);

it('names the owner role by cbox-cms.doctor.owner_role, or by the username of the owner connection this process has', function (): void {
    $withConnection = ['default' => 'pgsql', 'connections' => ['pgsql_owner' => ['driver' => 'pgsql', 'username' => 'cms_owner']]];
    $read = static fn (array $database, array $doctor): DoctorSettings => DoctorConfig::read(
        new Repository(['database' => $database, 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => $doctor]]),
        '/app',
    );

    expect($read($withConnection, [])->ownerRole)->toBe('cms_owner')
        ->and($read($withConnection, ['owner_role' => 'schema_owner'])->ownerRole)->toBe('schema_owner')
        ->and($read(['default' => 'pgsql'], ['owner_role' => 'schema_owner'])->ownerRole)->toBe('schema_owner')
        ->and($read(['default' => 'pgsql'], [])->ownerRole)->toBeNull();
});

it('wires the runtime checks in order and the dev checks after them', function (): void {
    $checks = app(DoctorChecks::class);
    $runtime = $checks->for(new DoctorRunOptions);
    $dev = array_slice($checks->for(new DoctorRunOptions(dev: true)), count($runtime));
    $ids = static fn (DoctorCheck ...$list): array => array_map(static fn (DoctorCheck $check): string => $check->id()->value, $list);

    expect($checks)->toBeInstanceOf(OrderedDoctorChecks::class)
        ->and($ids(...$runtime))->toBe([
            'php.version',
            'php.allow_url_fopen',
            'laravel.version',
            'postgres.reachable',
            'postgres.version',
            'postgres.app_role',
            'postgres.transaction_timeout',
            'postgres.idle_in_transaction_timeout',
            'postgres.prepared_transactions',
            'postgres.lc_messages',
            'postgres.ddl_privileges',
            'postgres.row_security',
            'postgres.extensions',
            'postgres.oldest_xact',
            'valkey.reachable',
            'partitions.runway',
            'registry.cache',
            'events.lag',
            'events.parked',
            'postgres.owner_credentials',
            'identity.operator_actor',
            // The identity module's, which its provider puts in cbox-cms.doctor.checks.
            'identity.connection',
            'identity.credential_isolation',
            'identity.argon2id',
            'identity.session_cookie',
            'identity.login_policy',
            'panel.branding',
            'panel.addons',
            'panel.dev_server',
        ])
        ->and($ids(...$dev))->toBe(['dev.node', 'dev.playwright', 'dev.chromium'])
        ->and(array_map(static fn (DoctorCheck $check): bool => $check->blocking(), $runtime))->toBe([true, true, true, true, true, true, true, true, true, true, true, true, true, false, true, false, true, false, false, false, false, true, true, true, true, true, false, false, true])
        ->and(array_map(static fn (DoctorCheck $check): bool => $check->blocking(), $dev))->toBe([false, false, false]);
});

it('gives the one failing check doctor.config when cbox-cms.doctor is invalid', function (): void {
    config(['cbox-cms.doctor.partition_runway_days' => -1]);

    $checks = app(DoctorChecks::class);
    $runtime = $checks->for(new DoctorRunOptions);

    expect($runtime)->toHaveCount(1)
        ->and($runtime[0])->toBeInstanceOf(InvalidConfigurationCheck::class)
        ->and($runtime[0]->id()->equals(new CheckId(InvalidConfigurationCheck::ID)))->toBeTrue()
        ->and($checks->for(new DoctorRunOptions(dev: true)))->toBe($runtime)
        ->and($runtime[0]->run()->cause)->toContain('partition_runway_days');
});

/**
 * The ids of the checks of one run.
 *
 * @return list<string>
 */
function configuredCheckIds(bool $dev): array
{
    return array_map(static fn (DoctorCheck $check): string => $check->id()->value, app(DoctorChecks::class)->for(new DoctorRunOptions(dev: $dev)));
}

/**
 * The cause of the one failing check doctor.config, which must be the only check of a run.
 */
function configurationFailure(): string
{
    $checks = app(DoctorChecks::class);
    $runtime = $checks->for(new DoctorRunOptions);

    expect($runtime)->toHaveCount(1)
        ->and($runtime[0])->toBeInstanceOf(InvalidConfigurationCheck::class)
        ->and($checks->for(new DoctorRunOptions(dev: true)))->toBe($runtime);

    return (string) $runtime[0]->run()->cause;
}

it('adds no checks of its own by default', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => require __DIR__.'/../../config/cbox-cms.php']), '/app');
    $leftOut = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner']]]), '/app');

    expect([$settings->checks, $settings->devChecks, $leftOut->checks, $leftOut->devChecks])->toBe([[], [], [], []]);
});

it('reads the class names of the checks an application or addon adds, in their order', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => [
        'checks' => [AddonReadyCheck::class, RepeatedIdCheck::class],
        'dev_checks' => [AddonToolCheck::class],
    ]]]), '/app');

    expect($settings->checks)->toBe([AddonReadyCheck::class, RepeatedIdCheck::class])
        ->and($settings->devChecks)->toBe([AddonToolCheck::class]);
});

it('refuses a check list that is not a list of classes that implement DoctorCheck', function (string $key, mixed $value, string $message): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cbox-cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => [$key => $value]]]);

    expect(fn (): DoctorSettings => DoctorConfig::read($config, '/app'))->toThrow(InvalidDoctorConfig::class, $message);
})->with([
    'a class name instead of a list' => ['checks', AddonReadyCheck::class, 'The setting cbox-cms.doctor.checks must be a list of class names of doctor checks; it is '],
    'a map instead of a list' => ['dev_checks', ['tool' => AddonToolCheck::class], 'The setting cbox-cms.doctor.dev_checks must be a list of class names of doctor checks; it is array.'],
    'null' => ['checks', null, 'The setting cbox-cms.doctor.checks must be a list of class names of doctor checks; it is null.'],
    'a number in the list' => ['checks', [AddonReadyCheck::class, 5], 'The setting cbox-cms.doctor.checks.1 must be the name of a class that implements Cbox\Cms\Contracts\Doctor\DoctorCheck; it is 5.'],
    'a class that does not exist' => ['checks', ['Acme\Missing\Check'], "The setting cbox-cms.doctor.checks.0 must be the name of a class that implements Cbox\Cms\Contracts\Doctor\DoctorCheck; it is 'Acme\\\\Missing\\\\Check'."],
    'a class that is no check' => ['dev_checks', [stdClass::class], "The setting cbox-cms.doctor.dev_checks.0 must be the name of a class that implements Cbox\Cms\Contracts\Doctor\DoctorCheck; it is 'stdClass'."],
    'the contract itself' => ['checks', [DoctorCheck::class], 'The setting cbox-cms.doctor.checks.0 must be the name of a class that implements'],
]);

it('runs the checks of cbox-cms.doctor.checks after the core\'s runtime checks, and those of dev_checks after the dev checks', function (): void {
    $core = configuredCheckIds(false);
    $coreDev = configuredCheckIds(true);

    // An application's setting is merged behind the checks the identity module's provider put there.
    config(['cbox-cms.doctor.checks' => [...(array) config('cbox-cms.doctor.checks'), AddonReadyCheck::class], 'cbox-cms.doctor.dev_checks' => [AddonToolCheck::class]]);

    $runtime = app(DoctorChecks::class)->for(new DoctorRunOptions);

    expect(configuredCheckIds(false))->toBe([...$core, AddonReadyCheck::ID])
        ->and(configuredCheckIds(true))->toBe([...$core, AddonReadyCheck::ID, ...array_slice($coreDev, count($core)), AddonToolCheck::ID])
        ->and($runtime[count($core)])->toBeInstanceOf(AddonReadyCheck::class)
        ->and($runtime[count($core)]->run()->passed())->toBeTrue();
});

it('builds each added check with the container, so a binding hands it what it looks at', function (): void {
    $fake = FakeDoctorCheck::failing(new CheckId('addon.fake'), blocking: false);
    app()->instance(FakeDoctorCheck::class, $fake);
    app()->when(UnbuildableCheck::class)->needs('$directory')->give('/srv/uploads');

    config(['cbox-cms.doctor.checks' => [UnbuildableCheck::class, FakeDoctorCheck::class]]);

    $runtime = app(DoctorChecks::class)->for(new DoctorRunOptions);
    [$directory, $added] = array_slice($runtime, -2);

    expect($added)->toBe($fake)
        ->and($directory)->toBeInstanceOf(UnbuildableCheck::class)
        ->and($directory instanceof UnbuildableCheck ? $directory->directory : null)->toBe('/srv/uploads');
});

it('gives the one failing check doctor.config when an added check cannot be used', function (string $key, string $class, string $cause): void {
    config(['cbox-cms.doctor.'.$key => [$class]]);

    expect(configurationFailure())->toContain($cause);
})->with([
    'a class the container cannot build' => ['checks', UnbuildableCheck::class, 'The check '.UnbuildableCheck::class.' in cbox-cms.doctor.checks cannot be used: Illuminate\Contracts\Container\BindingResolutionException: Unresolvable dependency resolving [Parameter #0 [ <required> string $directory ]]'],
    'an id that is not a check id' => ['dev_checks', InvalidIdCheck::class, 'The check '.InvalidIdCheck::class.' in cbox-cms.doctor.dev_checks cannot be used: Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck: The check id "Addon Ready" is invalid.'],
    'the id of a core check' => ['checks', RepeatedIdCheck::class, 'The checks in cbox-cms.doctor.checks and cbox-cms.doctor.dev_checks cannot run after the core\'s checks: The check "php.version" is listed twice.'],
    'a runtime check that requires a dev check' => ['checks', AddonToolCheck::class, 'The checks in cbox-cms.doctor.checks and cbox-cms.doctor.dev_checks cannot run after the core\'s checks: The check "addon.tool" requires "dev.node", which is not listed before it.'],
    'a blocking check that requires one that does not block' => ['checks', BlockingOnReadinessCheck::class, 'The checks in cbox-cms.doctor.checks and cbox-cms.doctor.dev_checks cannot run after the core\'s checks: The blocking check "addon.guard" requires "partitions.runway", which does not block.'],
    'a check whose blocking() throws' => ['checks', UndecidedBlockingCheck::class, 'The check '.UndecidedBlockingCheck::class.' in cbox-cms.doctor.checks cannot be used: LogicException: The addon has not decided whether this check blocks.'],
]);

it('gives doctor.config when an added check names a class that is no check', function (): void {
    config(['cbox-cms.doctor.checks' => [stdClass::class]]);

    expect(configurationFailure())->toContain('cbox-cms.doctor.checks.0 must be the name of a class that implements');
});

it('gives doctor.config when a binding makes an added check into something that is no check', function (): void {
    app()->bind(AddonReadyCheck::class, static fn (): stdClass => new stdClass);
    config(['cbox-cms.doctor.checks' => [AddonReadyCheck::class]]);

    expect(configurationFailure())->toBe('The check '.AddonReadyCheck::class.' in cbox-cms.doctor.checks cannot be used: UnexpectedValueException: The container gives stdClass, which does not implement Cbox\Cms\Contracts\Doctor\DoctorCheck.');
});
