<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Cms\Generated\GeneratedTypesServiceProvider;

/**
 * Boots the workbench application that the packages are tested and developed against.
 *
 * It wires the three Postgres roles from compose.yaml (PRD 4.2, 5.16, GUARDRAILS 6):
 * `pgsql` connects as the app role, which the application and the tests use,
 * `pgsql_owner` connects as the owner role, which runs migrations, and `pgsql_identity` connects
 * as the identity role, the only one that reaches the credential store of the local accounts,
 * whose connection cbox-cms.identity.connection names. They use the dedicated schema instead of
 * Laravel's default search_path of public; the owner's search path lists the credential store's
 * schema after it, so migrate:fresh rebuilds that too, and the identity role's search path is
 * the credential store's schema alone. The workbench is a
 * development application whose console runs the migrations and partition maintenance itself, so
 * its console processes get `pgsql_owner`, and the environment of its tests (phpunit.xml) and of
 * vendor/bin/testbench (testbench.yaml) declares them the maintenance process for cms:doctor with
 * CBOX_CMS_MAINTENANCE_PROCESS=true. A process of the workbench that serves HTTP, such as
 * `testbench serve`, does not get `pgsql_owner`: the core refuses to boot a process that serves
 * HTTP or runs queued jobs with the owner connection (PRD 4.2), so a queue worker of the workbench
 * does not boot either, and a production installation gives it to its maintenance process only.
 *
 * It also points cms:generate (PRD 11.12) at the workbench's schema root, owner app, at the schema
 * root of the fixture addon cboxdk/cms-fixture-addon, owner fixtureaddon, whose blueprint extension
 * of app:fixture_article an application's generation reads as it reads its own blueprints, and its
 * committed generated code and type table migrations (GUARDRAILS 2.6), relative to the monorepo
 * root, and cms:doctor at the
 * monorepo's vendor/composer/installed.json and at the root, where package.json and node_modules
 * are for --dev. Testbench's application links vendor/ into its base path only while a command runs, so
 * the default below the base path is not there in the tests.
 *
 * It turns off server-side rendering and the check for page components on disk for the
 * workbench's Inertia test page (boot()).
 *
 * It configures the event runner and the seeder's service actor from the environment
 * (configureEventRunner()), and the workbench's site, SITE, in cbox-cms.sites.
 *
 * It registers the service provider that cms:generate writes from the workbench's schema, which
 * binds the TypeCatalog contract to the generated catalog and each fixture type's record factory,
 * as an application registers its own.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    /** The environment variable that names the event runner's service actor. */
    public const string SERVICE_ACTOR = 'CBOX_CMS_EVENTS_SERVICE_ACTOR';

    /** The environment variable that names the seeder's service actor, as composer scale:check sets it. */
    public const string SEEDING_ACTOR = 'CBOX_CMS_SEEDING_SERVICE_ACTOR';

    /** The schema of the credential store of the local accounts, as docs/security/credential-store.md names it. */
    public const string CREDENTIAL_STORE = 'cms_identity';

    /** The handle of the workbench's site. */
    public const string SITE = 'workbench';

    /** The environment variable that picks the CDN driver; only fake is known. */
    public const string CDN_DRIVER = 'CBOX_CMS_CDN_DRIVER';

    public function register(): void
    {
        $this->app->register(GeneratedTypesServiceProvider::class);

        $config = $this->app->make(Repository::class);

        $config->set('cbox-cms.generators', [
            'root' => dirname(__DIR__, 3),
            'roots' => ['app' => 'workbench/schema', 'fixtureaddon' => 'workbench/addons/fixtureaddon/schema'],
            'php_directory' => 'workbench/app/Cms/Generated',
            'php_namespace' => 'Workbench\\App\\Cms\\Generated',
            'typescript_directory' => 'workbench/resources/js/cms/generated',
            'migrations_directory' => 'workbench/database/migrations/cms',
        ]);

        $config->set('cbox-cms.doctor.project_path', dirname(__DIR__, 3));
        $config->set('cbox-cms.doctor.vendor_manifest', dirname(__DIR__, 3).'/vendor/composer/installed.json');

        $this->configureEventRunner($config);

        // The workbench's one site (PRD 11.14), served by vendor/bin/testbench serve at its default
        // address, in Danish and English. cms:sites:sync, the last step of composer dev:prepare,
        // registers it with its root node, so a grant has a node to hold on.
        $config->set('cbox-cms.sites', [
            self::SITE => ['origin' => 'http://localhost:8000', 'locales' => ['da', 'en'], 'hosts' => ['127.0.0.1:8000']],
        ]);

        // The workbench is a development environment, and B1 part 1 offers no passkey or second
        // factor, so its login policy lets a member of staff log in locally with a password alone
        // (PRD 5.16). Every other environment keeps the default, passkey_or_two_factors.
        $config->set('cbox-cms.identity.policy.staff.local_factors', 'password');

        $app = $config->get('database.connections.pgsql');

        if (! is_array($app)) {
            return;
        }

        $app['search_path'] = $this->env('DB_SCHEMA', 'cms');

        $config->set('database.connections.pgsql', $app);
        $config->set('database.connections.pgsql_identity', array_merge($app, [
            'username' => $this->env('DB_IDENTITY_USERNAME', 'cms_identity'),
            'password' => $this->env('DB_IDENTITY_PASSWORD', ''),
            'search_path' => self::CREDENTIAL_STORE,
        ]));

        if (! $this->app->runningInConsole()) {
            return;
        }

        $config->set('database.connections.pgsql_owner', array_merge($app, [
            'username' => $this->env('DB_OWNER_USERNAME', 'cms_owner'),
            'password' => $this->env('DB_OWNER_PASSWORD', ''),
            'search_path' => $app['search_path'].','.self::CREDENTIAL_STORE,
        ]));
    }

    /**
     * The workbench's Inertia pages (GUARDRAILS 2.1) are rendered by the root view `app` in
     * workbench/resources/views, with no server-side rendering and no page components: the panel's
     * JavaScript comes with B1, so Inertia's test assertions do not look for a component on disk.
     * Set after every provider has registered, so Inertia's own defaults are merged first.
     */
    public function boot(): void
    {
        $config = $this->app->make(Repository::class);

        $config->set('inertia.ssr.enabled', false);
        $config->set('inertia.testing.ensure_pages_exist', false);
    }

    /**
     * The event runner of the workbench (PRD 7.6), set from its environment as an application sets
     * it in config/cbox-cms.php: CBOX_CMS_EVENTS_SERVICE_ACTOR names the service actor the
     * subscribers run as, and CBOX_CMS_CDN_DRIVER=fake purges the edge through the testkit's
     * FakeCdnDriver, the CDN of the walking skeleton (MILESTONES M1 point 5). Without them the
     * workbench keeps the core's defaults: no service actor and no CDN driver.
     */
    private function configureEventRunner(Repository $config): void
    {
        $actor = $this->env(self::SERVICE_ACTOR, '');

        if ($actor !== '') {
            $config->set('cbox-cms.events.runner.service_actor', $actor);
        }

        $seeder = $this->env(self::SEEDING_ACTOR, '');

        if ($seeder !== '') {
            $config->set('cbox-cms.seeding.service_actor', $seeder);
        }

        if ($this->env(self::CDN_DRIVER, '') === 'fake') {
            $config->set('cbox-cms.contracts.'.CdnDriver::class, FakeCdnDriver::class);
        }
    }

    private function env(string $key, string $default): string
    {
        $value = Env::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
