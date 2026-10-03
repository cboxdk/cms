<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Cbox\Cms\Identity\CredentialStore\Boundary\IdentityConfig;
use Cbox\Cms\Identity\Doctor\Adapter\ConfigLoginPolicyProbe;
use Cbox\Cms\Identity\Doctor\Adapter\ConfigSessionCookieProbe;
use Cbox\Cms\Identity\Doctor\Adapter\ConnectionCredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Adapter\PhpPasswordHashingProbe;
use Cbox\Cms\Identity\Doctor\Domain\Checks\Argon2idCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\CredentialIsolationCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\IdentityConnectionCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\LoginPolicyCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\SessionCookieCheck;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\LoginPolicyProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\SessionCookieProbe;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\Migrations\Migrator;

/*
 * The identity module in the workbench application (PRD 5.16): its configuration under
 * cbox-cms.identity, its migrations, its doctor checks in front of the ones an application adds,
 * and its classes as a scan root of cms:build.
 */

it('merges cbox-cms.identity with the identity role\'s connection, the login policy and the session cookie', function (): void {
    expect(config('cbox-cms.identity'))->toHaveKeys(['connection', 'policy', 'session'])
        ->and(config('cbox-cms.identity.connection'))->toBe('pgsql_identity')
        ->and(IdentityConfig::connection(app('config')))->toBe('pgsql_identity')
        ->and(IdentityConfig::connection(new Repository(['cbox-cms' => ['identity' => ['connection' => 7]]])))->toBeNull();
});

it('loads the migrations of the credential store', function (): void {
    expect(array_map(realpath(...), app(Migrator::class)->paths()))->toContain(realpath(__DIR__.'/../database/migrations'));
});

it('adds its five checks to cms:doctor in front of the ones the application names, and runs them after the core\'s', function (): void {
    $ids = array_map(static fn (DoctorCheck $check): string => $check->id()->value, app(DoctorChecks::class)->for(new DoctorRunOptions(dev: false)));

    expect(config('cbox-cms.doctor.checks'))->toBe(IdentityServiceProvider::DOCTOR_CHECKS)
        ->and(IdentityServiceProvider::DOCTOR_CHECKS)->toBe([IdentityConnectionCheck::class, CredentialIsolationCheck::class, Argon2idCheck::class, SessionCookieCheck::class, LoginPolicyCheck::class])
        ->and(array_slice($ids, -5))->toBe([IdentityConnectionCheck::ID, CredentialIsolationCheck::ID, Argon2idCheck::ID, SessionCookieCheck::ID, LoginPolicyCheck::ID])
        ->and($ids)->toContain('postgres.reachable')
        ->and(app(CredentialStoreProbe::class))->toBeInstanceOf(ConnectionCredentialStoreProbe::class)
        ->and(app(PasswordHashingProbe::class))->toBeInstanceOf(PhpPasswordHashingProbe::class)
        ->and(app(SessionCookieProbe::class))->toBeInstanceOf(ConfigSessionCookieProbe::class)
        ->and(app(LoginPolicyProbe::class))->toBeInstanceOf(ConfigLoginPolicyProbe::class);
});

it('keeps the checks an application adds after its own, each once, and leaves a setting that is not a list to the doctor', function (): void {
    $app = app();
    $config = app('config');
    $config->set('cbox-cms.doctor.checks', ['App\\Doctor\\QueueCheck', IdentityConnectionCheck::class]);
    new IdentityServiceProvider($app)->register();

    expect($config->get('cbox-cms.doctor.checks'))->toBe([...IdentityServiceProvider::DOCTOR_CHECKS, 'App\\Doctor\\QueueCheck']);

    $config->set('cbox-cms.doctor.checks', 'App\\Doctor\\QueueCheck');
    new IdentityServiceProvider($app)->register();

    expect($config->get('cbox-cms.doctor.checks'))->toBe('App\\Doctor\\QueueCheck');
});

it('declares the module\'s classes as a scan root of cboxdk/cms', function (): void {
    $roots = new IdentityServiceProvider(app())->scanRoots();

    expect($roots)->toHaveCount(1)
        ->and($roots[0]->package)->toBe(IdentityServiceProvider::PACKAGE)
        ->and(realpath($roots[0]->directory))->toBe(realpath(__DIR__.'/../src'));
});
