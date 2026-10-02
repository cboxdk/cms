<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\CiFiles;

/*
 * The development and test services of compose.yaml (GUARDRAILS 9, PRD 4.2): the cboxdk database
 * images with cbox-init as PID 1, set up as the db-baseimages README says, the server settings of
 * the operating contract as a conf.d drop-in, and the php toolbox as the host user. The CI files
 * extend these services; CiWorkflowTest holds them to each other. The php service reads the PHP
 * settings of the runtime contract from the drop-in docker/php/conf.d/cms.ini; PhpDropInTest starts
 * PHP with it.
 */

const PHP_DROP_IN = 'docker/php/conf.d/cms.ini';

/**
 * The php service's mount of the PHP settings drop-in, as compose.yaml writes it.
 */
function composePhpDropIn(): string
{
    return './'.PHP_DROP_IN.':/usr/local/etc/php/conf.d/zz-cms.ini:ro';
}

/**
 * @return array<array-key, mixed>
 */
function composeService(string $name): array
{
    $service = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE), 'services', $name);

    expect($service)->toBeArray();

    return is_array($service) ? $service : [];
}

it('runs PHP 8.5 of the v1 channel, Postgres 18 and Valkey 8 on the cboxdk images and nothing else', function (): void {
    $services = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE), 'services');

    expect(is_array($services) ? array_keys($services) : null)->toBe(['php', 'postgres', 'valkey'])
        ->and(CiFiles::at(composeService('php'), 'image'))->toBe('ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1')
        ->and(CiFiles::at(composeService('postgres'), 'image'))->toBe('ghcr.io/cboxdk/postgres:18')
        ->and(CiFiles::at(composeService('valkey'), 'image'))->toBe('ghcr.io/cboxdk/valkey:8');
});

it('keeps cbox-init as the entry point of the database images and asks it for readiness', function (string $name): void {
    $service = composeService($name);

    expect(array_keys($service))->not->toContain('entrypoint')
        ->and(array_keys($service))->not->toContain('command')
        ->and(CiFiles::at($service, 'healthcheck', 'test'))->toBe(['CMD', 'test', '-f', '/tmp/cbox-ready'])
        ->and(CiFiles::at($service, 'healthcheck', 'start_period'))->toBeString();
})->with(['postgres', 'valkey']);

it('gives Postgres its data mount, the conf.d drop-in, the init script and 130 seconds to stop', function (): void {
    $postgres = composeService('postgres');

    expect(CiFiles::at($postgres, 'stop_grace_period'))->toBe('130s')
        ->and(CiFiles::at($postgres, 'volumes'))->toBe([
            'postgres-data:/var/lib/postgresql',
            './docker/postgres/conf.d:/etc/postgresql/conf.d:ro',
            './docker/postgres/initdb.d:/docker-entrypoint-initdb.d:ro',
            './docker/postgres/sql:/cms-init:ro',
        ])
        ->and(CiFiles::at($postgres, 'ports'))->toBe(['127.0.0.1:54317:5432']);
});

it('sets the server settings of the operating contract in docker/postgres/conf.d/cms.conf, not on the command line', function (): void {
    expect(CiFiles::codeLines('docker/postgres/conf.d/cms.conf'))->toBe([
        'max_prepared_transactions = 0',
        "lc_messages = 'C'",
        'max_locks_per_transaction = 256',
        'fsync = off',
        'synchronous_commit = off',
        'full_page_writes = off',
    ])
        ->and(CiFiles::text(CiFiles::COMPOSE))->not->toContain('max_prepared_transactions=');
});

it('provisions the roles of the operating contract with the init script', function (): void {
    $environment = CiFiles::strings(composeService('postgres'), 'environment');
    $roles = CiFiles::text('docker/postgres/sql/roles.sql');

    expect($environment['CMS_OWNER_ROLE'] ?? null)->toBe('cms_owner')
        ->and($environment['CMS_APP_ROLE'] ?? null)->toBe('cms_app')
        ->and($environment['CMS_APP_TRANSACTION_TIMEOUT'] ?? null)->toBe('5s')
        ->and($roles)->toContain('ALTER ROLE :"owner_role" WITH LOGIN NOSUPERUSER CREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS')
        ->and($roles)->toContain('GRANT pg_signal_backend TO :"owner_role";')
        ->and(substr_count($roles, 'GRANT '))->toBe(1)
        ->and($roles)->toContain('ALTER ROLE :"app_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS')
        ->and($roles)->toContain('ALTER ROLE :"app_role" SET transaction_timeout = :\'app_transaction_timeout\';')
        ->and($environment['CMS_IDENTITY_ROLE'] ?? null)->toBe('cms_identity')
        ->and($roles)->toContain('ALTER ROLE :"identity_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS')
        ->and($roles)->toContain('ALTER ROLE :"identity_role" SET search_path = cms_identity;');
});

it('gives Valkey its data mount and the time cbox-init needs to save before it stops', function (): void {
    $valkey = composeService('valkey');

    expect(CiFiles::at($valkey, 'volumes'))->toBe(['valkey-data:/data'])
        ->and(CiFiles::at($valkey, 'stop_grace_period'))->toBe('40s')
        ->and(CiFiles::at($valkey, 'ports'))->toBe(['127.0.0.1:63797:6379']);
});

it('runs the php container as the host user that composer services:up exports, never as root', function (): void {
    $php = composeService('php');

    expect(CiFiles::at($php, 'user'))->toBe('${CMS_UID:-1000}:${CMS_GID:-1000}')
        ->and(CiFiles::strings($php, 'environment')['HOME'] ?? null)->toBe('/tmp')
        ->and(CiFiles::at($php, 'volumes'))->toBe(['.:/var/www/html', composePhpDropIn()]);
});

it('mounts the PHP settings of the runtime contract read-only into conf.d, after the image\'s own files, with allow_url_fopen off', function (): void {
    $volumes = CiFiles::at(composeService('php'), 'volumes');
    $mount = explode(':', composePhpDropIn());
    $file = Phpstan::root().'/'.PHP_DROP_IN;

    expect(is_file($file))->toBeTrue();

    $settings = parse_ini_file($file, false, INI_SCANNER_TYPED);

    expect($volumes)->toBeArray()->toContain(composePhpDropIn())
        ->and($mount)->toHaveCount(3)
        ->and($mount[0])->toBe('./'.PHP_DROP_IN)
        ->and(dirname($mount[1]))->toBe('/usr/local/etc/php/conf.d')
        ->and($mount[2])->toBe('ro')
        // PHP reads conf.d in name order, and the image's 99-cbox.ini sets allow_url_fopen = On.
        ->and(strcmp(basename($mount[1]), '99-cbox.ini'))->toBeGreaterThan(0)
        ->and(strcmp(basename($mount[1]), 'docker-php-ext-zzz.ini'))->toBeGreaterThan(0)
        ->and($settings)->toBe(['allow_url_fopen' => false]);
});

it('runs composer services:up and services:down through tools/bin/services.php, which ServicesScriptTest covers', function (string $script): void {
    $composer = json_decode(CiFiles::text('composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(is_array($composer) ? CiFiles::at($composer, 'scripts', 'services:'.$script) : null)->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@php tools/bin/services.php '.$script,
    ]);
})->with(['up', 'down']);
