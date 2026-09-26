<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Tooling\CiFiles;

/*
 * The development and test services of compose.yaml (GUARDRAILS 9, PRD 4.2): the cboxdk database
 * images with cbox-init as PID 1, set up as the db-baseimages README says, the server settings of
 * the operating contract as a conf.d drop-in, and the php toolbox as the host user. The CI files
 * extend these services; CiWorkflowTest holds them to each other.
 */

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
        ->and(CiFiles::at($postgres, 'ports'))->toBe(['54317:5432']);
});

it('sets the server settings of the operating contract in docker/postgres/conf.d/cms.conf, not on the command line', function (): void {
    expect(CiFiles::codeLines('docker/postgres/conf.d/cms.conf'))->toBe(['max_prepared_transactions = 0', "lc_messages = 'C'"])
        ->and(CiFiles::text(CiFiles::COMPOSE))->not->toContain('max_prepared_transactions=');
});

it('provisions the roles of the operating contract with the init script', function (): void {
    $environment = CiFiles::strings(composeService('postgres'), 'environment');
    $roles = CiFiles::text('docker/postgres/sql/roles.sql');

    expect($environment['CMS_OWNER_ROLE'] ?? null)->toBe('cms_owner')
        ->and($environment['CMS_APP_ROLE'] ?? null)->toBe('cms_app')
        ->and($environment['CMS_APP_TRANSACTION_TIMEOUT'] ?? null)->toBe('5s')
        ->and($roles)->toContain('ALTER ROLE :"app_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS')
        ->and($roles)->toContain('ALTER ROLE :"app_role" SET transaction_timeout = :\'app_transaction_timeout\';');
});

it('gives Valkey its data mount and the time cbox-init needs to save before it stops', function (): void {
    $valkey = composeService('valkey');

    expect(CiFiles::at($valkey, 'volumes'))->toBe(['valkey-data:/data'])
        ->and(CiFiles::at($valkey, 'stop_grace_period'))->toBe('40s')
        ->and(CiFiles::at($valkey, 'ports'))->toBe(['63797:6379']);
});

it('runs the php container as the host user that composer services:up exports, never as root', function (): void {
    $php = composeService('php');
    $composer = json_decode(CiFiles::text('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $script = is_array($composer) ? CiFiles::at($composer, 'scripts', 'services:up') : null;

    expect(CiFiles::at($php, 'user'))->toBe('${CMS_UID:-1000}:${CMS_GID:-1000}')
        ->and(CiFiles::strings($php, 'environment')['HOME'] ?? null)->toBe('/tmp')
        ->and($script)->toBe([
            'Composer\\Config::disableProcessTimeout',
            'export CMS_UID="$(id -u)" CMS_GID="$(id -g)" && docker compose up -d --wait',
        ]);
});
