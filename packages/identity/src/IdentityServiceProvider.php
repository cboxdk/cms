<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Identity\CredentialStore\Boundary\IdentityConfig;
use Cbox\Cms\Identity\Doctor\Adapter\ConnectionCredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Adapter\PhpPasswordHashingProbe;
use Cbox\Cms\Identity\Doctor\Domain\Checks\Argon2idCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\CredentialIsolationCheck;
use Cbox\Cms\Identity\Doctor\Domain\Checks\IdentityConnectionCheck;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Cbox\Cms\Identity\LoginPolicy\Adapter\PostgresIdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the identity module, the login of Cbox CMS (PRD 5.16), in a Laravel application.
 * Loaded through package discovery. The actor aggregate and the actor commands are the core's
 * (Cbox\Cms\Core\Identity); this module is the local accounts and their credential store.
 *
 * Merges `cbox-cms.identity` and loads the migrations of the credential store, which run as the
 * owner role. Adds its checks to cms:doctor as an application adds its own, in front of those
 * `cbox-cms.doctor.checks` names: identity.connection, identity.credential_isolation and
 * identity.argon2id, with their probes. Binds the login policy of `cbox-cms.identity.policy`, read
 * when it is first asked for, and the IdP links it reads (PRD 5.16, invariant 38). Declares the module's classes as a scan root for
 * cms:build (PRD 13.2).
 */
#[Internal]
final class IdentityServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    /**
     * The checks the module adds to cms:doctor, in their order.
     *
     * @var list<class-string<DoctorCheck>>
     */
    public const array DOCTOR_CHECKS = [IdentityConnectionCheck::class, CredentialIsolationCheck::class, Argon2idCheck::class];

    #[Override]
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/identity.php', IdentityConfig::KEY);

        $config = $this->app->make(Repository::class);
        $key = DoctorConfig::CONFIG_KEY.'.'.DoctorConfig::CHECKS;
        $added = $config->get($key) ?? [];

        // A setting that is not a list stays as it is, so the doctor reports it as doctor.config.
        if (is_array($added)) {
            $config->set($key, array_values(array_unique([...self::DOCTOR_CHECKS, ...$added], SORT_REGULAR)));
        }

        $this->app->singleton(LoginPolicy::class, static fn (Application $app): LoginPolicy => LoginPolicyConfig::read($app->make(Repository::class)));
        $this->app->singleton(IdpLinks::class, static fn (Application $app): IdpLinks => new PostgresIdpLinks(
            $app->make(DatabaseManager::class),
            IdentityConfig::connection($app->make(Repository::class)),
        ));
        $this->app->bind(PasswordHashingProbe::class, PhpPasswordHashingProbe::class);
        $this->app->bind(
            static function (Application $app): CredentialStoreProbe {
                $connection = IdentityConfig::connection($app->make(Repository::class));

                return new ConnectionCredentialStoreProbe(
                    $app->make(DoctorConnection::class),
                    $connection === null ? null : new DoctorConnection(
                        $app->make(DatabaseManager::class),
                        $app->make(Repository::class),
                        $connection,
                        ConnectionCredentialStoreProbe::IDENTITY_CONNECTION,
                        $app->make(DoctorSettings::class)->connectTimeoutSeconds,
                    ),
                );
            },
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
