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
use Illuminate\Config\Repository;

/*
 * The settings under cms.doctor, their defaults, and the checks the core wires from them.
 */

it('reads the defaults of the core package', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cms' => require __DIR__.'/../../config/cms.php']), '/app');

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

it('falls back to its own defaults for the settings cms.doctor leaves out', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cms' => ['database' => ['owner_connection' => 'pgsql_owner']]]), '/srv/app');

    expect($settings->redisConnection)->toBe('default')
        ->and($settings->connectTimeoutSeconds)->toBe(3)
        ->and($settings->runwayDays)->toBe(7)
        ->and($settings->vendorManifest)->toBe('/srv/app/vendor/composer/installed.json')
        ->and($settings->projectPath)->toBe('/srv/app')
        ->and($settings->nodeMinimum)->toBe('22.13.0');
});

it('refuses an empty connection name', function (string $key): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => [$key => '']]]);

    expect(static fn (): DoctorSettings => DoctorConfig::read($config, '/srv/app'))
        ->toThrow(InvalidDoctorConfig::class, "cms.doctor.{$key}");
})->with(['connection', 'owner_connection', 'redis_connection']);

it('points the workbench at the monorepo\'s vendor manifest and node_modules', function (): void {
    $settings = app(DoctorSettings::class);
    $root = dirname(__DIR__, 4);

    expect($settings->vendorManifest)->toBe($root.'/vendor/composer/installed.json')
        ->and($settings->projectPath)->toBe($root)
        ->and(is_file($settings->vendorManifest))->toBeTrue();
});

it('takes the connection and paths the application sets', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cms' => ['doctor' => [
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

it('takes the owner connection from cms.database.owner_connection when cms.doctor has none', function (): void {
    $settings = DoctorConfig::read(new Repository(['database' => ['default' => 'pgsql'], 'cms' => ['database' => ['owner_connection' => 'pgsql_ddl']]]), '/app');

    expect($settings->ownerConnection)->toBe('pgsql_ddl');
});

it('refuses a missing owner connection, as when cms.database.owner_connection is null', function (): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cms' => ['database' => ['owner_connection' => null]]]);

    expect(fn (): DoctorSettings => DoctorConfig::read($config, '/app'))
        ->toThrow(InvalidDoctorConfig::class, 'The setting cms.doctor.owner_connection must be a connection name; it is null.');
});

it('refuses invalid settings with the key and the value', function (string $key, mixed $value, string $message): void {
    $config = new Repository(['database' => ['default' => 'pgsql'], 'cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => [$key => $value]]]);

    expect(fn (): DoctorSettings => DoctorConfig::read($config, '/app'))->toThrow(InvalidDoctorConfig::class, $message);
})->with([
    ['connection', 7, 'The setting cms.doctor.connection must be a connection name; it is 7.'],
    ['owner_connection', false, 'The setting cms.doctor.owner_connection must be a connection name; it is false.'],
    ['redis_connection', '', "cms.doctor.redis_connection must be a connection name; it is ''."],
    ['connect_timeout_seconds', 0, 'cms.doctor.connect_timeout_seconds must be a whole number of at least 1; it is 0.'],
    ['partition_runway_days', '7', "cms.doctor.partition_runway_days must be a whole number of at least 1; it is '7'."],
    ['vendor_manifest', ['x'], 'cms.doctor.vendor_manifest must be a path, or null for the default; it is array.'],
    ['node_minimum', '22', "cms.doctor.node_minimum must be a version such as \"22.13.0\"; it is '22'."],
    ['owner_role', '', "cms.doctor.owner_role must be a role name, or null for the username of the owner connection; it is ''."],
    ['owner_role', 5, 'cms.doctor.owner_role must be a role name, or null for the username of the owner connection; it is 5.'],
    ['maintenance_process', 'yes', "cms.doctor.maintenance_process must be true or false; it is 'yes'."],
]);

it('names the owner role by cms.doctor.owner_role, or by the username of the owner connection this process has', function (): void {
    $withConnection = ['default' => 'pgsql', 'connections' => ['pgsql_owner' => ['driver' => 'pgsql', 'username' => 'cms_owner']]];
    $read = static fn (array $database, array $doctor): DoctorSettings => DoctorConfig::read(
        new Repository(['database' => $database, 'cms' => ['database' => ['owner_connection' => 'pgsql_owner'], 'doctor' => $doctor]]),
        '/app',
    );

    expect($read($withConnection, [])->ownerRole)->toBe('cms_owner')
        ->and($read($withConnection, ['owner_role' => 'schema_owner'])->ownerRole)->toBe('schema_owner')
        ->and($read(['default' => 'pgsql'], ['owner_role' => 'schema_owner'])->ownerRole)->toBe('schema_owner')
        ->and($read(['default' => 'pgsql'], [])->ownerRole)->toBeNull()
        ->and($read(['default' => 'pgsql'], ['maintenance_process' => true])->maintenanceProcess)->toBeTrue();
});

it('wires the runtime checks in order and the dev checks after them', function (): void {
    $checks = app(DoctorChecks::class);
    $runtime = $checks->for(new DoctorRunOptions);
    $dev = array_slice($checks->for(new DoctorRunOptions(dev: true)), count($runtime));
    $ids = static fn (DoctorCheck ...$list): array => array_map(static fn (DoctorCheck $check): string => $check->id()->value, $list);

    expect($checks)->toBeInstanceOf(OrderedDoctorChecks::class)
        ->and($ids(...$runtime))->toBe([
            'php.version',
            'laravel.version',
            'postgres.reachable',
            'postgres.version',
            'postgres.app_role',
            'postgres.transaction_timeout',
            'postgres.prepared_transactions',
            'postgres.lc_messages',
            'postgres.ddl_privileges',
            'postgres.row_security',
            'valkey.reachable',
            'partitions.runway',
            'registry.cache',
            'postgres.owner_credentials',
        ])
        ->and($ids(...$dev))->toBe(['dev.node', 'dev.playwright', 'dev.chromium'])
        ->and(array_map(static fn (DoctorCheck $check): bool => $check->blocking(), $runtime))->toBe([true, true, true, true, true, true, true, true, true, true, true, false, true, false])
        ->and(array_map(static fn (DoctorCheck $check): bool => $check->blocking(), $dev))->toBe([false, false, false]);
});

it('gives the one failing check doctor.config when cms.doctor is invalid', function (): void {
    config(['cms.doctor.partition_runway_days' => -1]);

    $checks = app(DoctorChecks::class);
    $runtime = $checks->for(new DoctorRunOptions);

    expect($runtime)->toHaveCount(1)
        ->and($runtime[0])->toBeInstanceOf(InvalidConfigurationCheck::class)
        ->and($runtime[0]->id()->equals(new CheckId(InvalidConfigurationCheck::ID)))->toBeTrue()
        ->and($checks->for(new DoctorRunOptions(dev: true)))->toBe($runtime)
        ->and($runtime[0]->run()->cause)->toContain('partition_runway_days');
});
