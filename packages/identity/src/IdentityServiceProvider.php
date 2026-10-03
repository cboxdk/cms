<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorSettings;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Cbox\Cms\Identity\BreachedPasswords\Adapter\HibpBreachedPasswords;
use Cbox\Cms\Identity\Cli\Console\IdentityPruneCommand;
use Cbox\Cms\Identity\Cli\Console\StaffCreateCommand;
use Cbox\Cms\Identity\Cli\Console\StaffResetLinkCommand;
use Cbox\Cms\Identity\CredentialStore\Adapter\PostgresLocalCredentialStore;
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
use Cbox\Cms\Identity\LocalAccounts\Adapter\Argon2idPasswordHasher;
use Cbox\Cms\Identity\LocalAccounts\Boundary\LocalAccountsConfig;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Identity\Login\Adapter\ValkeyLoginThrottle;
use Cbox\Cms\Identity\Login\Boundary\LoginThrottleConfig;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\LoginPolicy\Adapter\PostgresIdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Identity\PasswordReset\Boundary\PasswordResetConfig;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Identity\Sessions\Adapter\SessionCredentialVerifier;
use Cbox\Cms\Identity\Sessions\Adapter\ValkeySessionStore;
use Cbox\Cms\Identity\Sessions\Boundary\SessionCookieConfig;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InsecureSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SessionCounters;
use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory;
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
 * identity.argon2id, identity.session_cookie and identity.login_policy, with their probes. Binds
 * the login policy of `cbox-cms.identity.policy` for the application's environment, read when it is
 * first asked for, and the IdP links it reads (PRD
 * 5.16, invariant 38). Binds BreachedPasswords to the class `cbox-cms.contracts` names for it,
 * HibpBreachedPasswords unless the application names another, and LocalCredentialStore to the
 * class `cbox-cms.contracts` names for it, PostgresLocalCredentialStore on the identity connection
 * unless the application names another. Binds the Argon2id PasswordHasher at
 * `cbox-cms.identity.passwords.argon2id` and the LocalConnection with the installation's local
 * issuer, and registers cms:staff:create in the console. Binds the session cookie of the
 * environment, the session store and the login throttle in Valkey (`cbox-cms.identity.login`), the
 * password reset's settings and its own throttle (`cbox-cms.identity.password_reset`), registers
 * cms:staff:reset-link and cms:identity:prune and schedules the prune every hour in the maintenance
 * process, and
 * puts the session verifier in front of the bound CredentialVerifier with the container's extend(),
 * so a session is a credential of every surface and the core never names this module. Refuses to
 * boot a process that serves HTTP when the session cookie of its environment is invalid or not safe
 * there, or when its login policy is invalid or lets staff log in locally with a password alone
 * outside local and testing (PRD 5.16). Declares the module's
 * classes as a scan root for cms:build (PRD 13.2).
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
    public const array DOCTOR_CHECKS = [IdentityConnectionCheck::class, CredentialIsolationCheck::class, Argon2idCheck::class, SessionCookieCheck::class, LoginPolicyCheck::class];

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

        $this->app->singleton(LoginPolicy::class, static fn (Application $app): LoginPolicy => LoginPolicyConfig::read($app->make(Repository::class), $app->environment()));
        $this->app->singleton(IdpLinks::class, static fn (Application $app): IdpLinks => new PostgresIdpLinks(
            $app->make(DatabaseManager::class),
            IdentityConfig::connection($app->make(Repository::class)),
        ));

        // The default BreachedPasswords is the module's, on the Have I Been Pwned range API through
        // the egress gateway. An application replaces it in cbox-cms.contracts, as any contract.
        $contract = ContractBindings::CONFIG_KEY.'.'.BreachedPasswords::class;

        if ($config->get($contract) === null) {
            $config->set($contract, HibpBreachedPasswords::class);
        }

        $this->app->singleton(
            BreachedPasswords::class,
            static fn (Application $app): BreachedPasswords => $app->make(ContractBindings::class)->resolve($app, BreachedPasswords::class),
        );

        // The local accounts: the store of their credentials, bound as any contract with the
        // module's Postgres store as the default, the Argon2id hasher at the installation's
        // parameters, and the local connection with the installation's local issuer.
        $store = ContractBindings::CONFIG_KEY.'.'.LocalCredentialStore::class;

        if ($config->get($store) === null) {
            $config->set($store, PostgresLocalCredentialStore::class);
        }

        $this->app->bind(PostgresLocalCredentialStore::class, static fn (Application $app): PostgresLocalCredentialStore => new PostgresLocalCredentialStore(
            $app->make(DatabaseManager::class),
            IdentityConfig::connection($app->make(Repository::class)),
            $app->make(Clock::class),
        ));
        $this->app->singleton(
            LocalCredentialStore::class,
            static fn (Application $app): LocalCredentialStore => $app->make(ContractBindings::class)->resolve($app, LocalCredentialStore::class),
        );
        $this->app->singleton(PasswordHasher::class, static fn (Application $app): PasswordHasher => new Argon2idPasswordHasher(LocalAccountsConfig::argon2id($app->make(Repository::class))));
        $this->app->singleton(LocalConnection::class, static fn (Application $app): LocalConnection => new LocalConnection(
            $app->make(LocalCredentialStore::class),
            $app->make(PasswordHasher::class),
            LocalAccountsConfig::issuer($app->make(Repository::class)),
            $app->make(Clock::class),
        ));

        $this->app->bind(PasswordHashingProbe::class, PhpPasswordHashingProbe::class);
        $this->app->bind(SessionCookieProbe::class, ConfigSessionCookieProbe::class);
        $this->app->bind(LoginPolicyProbe::class, ConfigLoginPolicyProbe::class);
        $this->app->singleton(SessionCookie::class, static fn (Application $app): SessionCookie => SessionCookieConfig::read($app->make(Repository::class), $app->environment()));
        $this->app->singleton(SessionStore::class, static fn (Application $app): SessionStore => new ValkeySessionStore(
            $app->make(Factory::class),
            $app->make(Clock::class),
        ));
        $this->app->singleton(LoginThrottleSettings::class, static fn (Application $app): LoginThrottleSettings => LoginThrottleConfig::read($app->make(Repository::class)));
        $this->app->singleton(LoginThrottle::class, static fn (Application $app): LoginThrottle => new ValkeyLoginThrottle(
            $app->make(Factory::class),
            $app->make(LoginThrottleSettings::class),
        ));
        // The password reset (PRD 5.16): its settings, read when first asked for, and its own
        // throttle of the requests for a link, the login throttle's script under another prefix
        // with the limits of cbox-cms.identity.password_reset.throttle.
        $this->app->singleton(ResetSettings::class, static fn (Application $app): ResetSettings => PasswordResetConfig::read($app->make(Repository::class)));
        $this->app->when(RequestPasswordReset::class)->needs(LoginThrottle::class)->give(static fn (Application $app): LoginThrottle => new ValkeyLoginThrottle(
            $app->make(Factory::class),
            LoginThrottleConfig::read($app->make(Repository::class), LoginThrottleConfig::RESET_KEY),
            null,
            ValkeyLoginThrottle::RESET_KEY,
        ));
        $this->app->extend(CredentialVerifier::class, static fn (CredentialVerifier $verifier, Application $app): CredentialVerifier => new SessionCredentialVerifier(
            $verifier,
            $app->make(SessionStore::class),
            $app->make(ActorDirectory::class),
            $app->make(LoginPolicy::class),
            $app->make(Clock::class),
            $app->make(SessionCounters::class),
        ));
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

    /**
     * @throws InvalidSessionCookie when a process that serves HTTP has no valid session cookie
     * @throws InsecureSessionCookie when a process that serves HTTP has a session cookie that is not safe in its environment
     * @throws InvalidLoginPolicy when a process that serves HTTP has a login policy that is invalid or may not hold in its environment
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([StaffCreateCommand::class, StaffResetLinkCommand::class, IdentityPruneCommand::class]);
        }

        // Pruning the reset tokens is the maintenance process's (PRD 4.2), the process with the
        // owner connection, so the web and queue processes schedule nothing.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule, Application $app): void {
            if (CoreServiceProvider::ownerConnectionConfigured($app->make(Repository::class))) {
                $schedule->command(IdentityPruneCommand::NAME)->hourly();
            }
        });
        $this->refuseAnUnsafeSessionCookie();
        $this->refuseAnUnsafeLoginPolicy();
    }

    /**
     * A process that serves HTTP decides logins (PRD 5.16), so it stops here, before the first one,
     * when the login policy of its environment is invalid or, outside local and testing, lets staff
     * log in locally with a password alone. Console processes boot, so cms:doctor can say why with
     * identity.login_policy.
     *
     * @throws InvalidLoginPolicy
     */
    private function refuseAnUnsafeLoginPolicy(): void
    {
        if (ProcessWorkload::of($this->app) !== Workload::Http) {
            return;
        }

        $this->app->make(LoginPolicy::class);
    }

    /**
     * The session cookie is the credential of a person (PRD 5.16). A process that serves HTTP
     * stops here, before it sets one, when the cookie of its environment is invalid or, outside
     * local and testing, not Secure, not named with the __Host- prefix or SameSite=None. Console
     * processes boot, so cms:doctor can say why with identity.session_cookie.
     *
     * @throws InvalidSessionCookie
     * @throws InsecureSessionCookie
     */
    private function refuseAnUnsafeSessionCookie(): void
    {
        if (ProcessWorkload::of($this->app) !== Workload::Http) {
            return;
        }

        $environment = $this->app->environment();
        $cookie = $this->app->make(SessionCookie::class);

        if (! $cookie->safeIn($environment)) {
            throw InsecureSessionCookie::in($environment, $cookie);
        }
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
