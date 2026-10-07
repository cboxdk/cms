<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\BuildCommand;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Core\Tests\Registry\Providers\FixtureRootProvider;
use Cbox\Cms\Core\Tests\Registry\Providers\PanelAddonProvider;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\Slug\Domain\Commands\SetArticleSlug;

/*
 * cms:build in the testbench application: it compiles the scan roots the providers declare and
 * writes the two files, or prints each problem with its code and exits with 65.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
    FixtureRootProvider::$fixture = 'Valid';
});

/**
 * Runs cms:build and returns its exit code and output lines.
 *
 * @return array{int, list<string>}
 */
function buildCommand(): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:build');

    return [$status, array_values(array_filter(array_map(trim(...), explode("\n", $artisan->output())), static fn (string $line): bool => $line !== ''))];
}

/**
 * The warnings every build of the workbench prints: the fixture addon's twenty-one contributions,
 * one of every kind on every point of block B1, all experimental points (decision D4), one warning
 * each, in the registry's order, which is the order of FixtureAddonServiceProvider::contributions().
 *
 * @return list<string>
 */
function fixtureAddonWarnings(): array
{
    $warning = static fn (string $contribution, string $point): string => sprintf('[registry_panel_point_experimental] The contribution %s of addon "fixtureaddon" (cboxdk/cms-fixture-addon) contributes to %s, which is experimental and may change in a minor release of the panel API.', $contribution, $point);

    return [
        $warning('fixtureaddon.slug-hint', 'command.form.checks@1'),
        $warning('fixtureaddon.slug-override', 'command.form.checks@1'),
        $warning('fixtureaddon.slug-shape', 'command.form.checks@1'),
        $warning('fixtureaddon.slug-review', 'command.form.steps@1'),
        $warning('fixtureaddon.slug-help', 'command.form.aside@1'),
        $warning('fixtureaddon.submit-note', 'command.form.submit@1'),
        $warning('fixtureaddon.receipt-note', 'command.form.receipt@1'),
        $warning('fixtureaddon.dry-run-note', 'command.form.dryrun@1'),
        $warning('fixtureaddon.slug-input', 'command.form.field@1'),
        $warning('fixtureaddon.self-grant', 'command.form.checks@1'),
        $warning('fixtureaddon.four-eyes', 'command.form.steps@1'),
        $warning('fixtureaddon.articles', 'shell.page@1'),
        $warning('fixtureaddon.articles-link', 'shell.nav@1'),
        $warning('fixtureaddon.new-article', 'shell.user-menu@1'),
        $warning('fixtureaddon.activity', 'panel.observe.command@1'),
        $warning('fixtureaddon.recent-activity', 'account.me.sections@1'),
        $warning('fixtureaddon.my-articles', 'account.me.sections@1'),
        $warning('fixtureaddon.faulty', 'account.me.sections@1'),
        $warning('fixtureaddon.four-eyes-note', 'access.grants.sections@1'),
        $warning('fixtureaddon.articles-permission', 'access.roles.sections@1'),
        $warning('fixtureaddon.login-notice', 'login.notice@1'),
    ];
}

it('is registered', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:build')
        ->and(app(Kernel::class)->all()['cms:build'])->toBeInstanceOf(BuildCommand::class);
});

it('writes the eight registries to the application\'s bootstrap/cache/cms, and removes the files it no longer writes', function (): void {
    $directory = app()->bootstrapPath('cache/cms');

    if (! is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    file_put_contents($directory.'/slots.php', "<?php return ['entries' => [], 'format' => 1, 'registry' => 'slots'];\n");

    [$status, $output] = buildCommand();

    expect($status)->toBe(0)
        ->and($output)->toBe([
            // The fixture addon contributes to every experimental point of block B1, which the build
            // warns about per contribution (decision D4: every point of B1 is experimental).
            ...fixtureAddonWarnings(),
            // The kernel's actions, and the fixture addon's query fixtureaddon.articles and command
            // fixtureaddon.slug.set, each on REST and Inertia.
            'actions: 26',
            // The workbench's fixture addon, which its allowlist names.
            'addons: 1',
            'commands: 18',
            // The workbench's fixture addon, which package discovery registers: its four hooks and
            // its extension of app:fixture_article.
            'hooks: 4',
            'panel: 15',
            'rest: 20',
            'schema: 1',
            'subscribers: 1',
            sprintf('Registry written to %s.', $directory),
        ])
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'openapi.json', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php']);
});

it('adds what an addon provider\'s scan root declares', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->instance(OpenApiDocuments::class, RegistryFixtures::documents($directory));
    app()->register(FixtureRootProvider::class);

    [$status, $output] = buildCommand();

    expect($status)->toBe(0)
        ->and(array_slice($output, 0, 27))->toBe([...fixtureAddonWarnings(), 'actions: 28', 'addons: 1', 'commands: 19', 'hooks: 5', 'panel: 16', 'rest: 21'])
        ->and(RegistryFixtures::load($directory.'/commands.php'))->toMatchArray(['entries' => [[
            'class' => GrantBootstrapRole::class,
            'name' => 'access.bootstrap',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => ActivateActor::class,
            'name' => 'actor.activate',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => DeactivateActor::class,
            'name' => 'actor.deactivate',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => RegisterActor::class,
            'name' => 'actor.register',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => CreateEntry::class,
            'name' => 'entry.create',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => PublishEntry::class,
            'name' => 'entry.publish',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => ReviseEntry::class,
            'name' => 'entry.revise',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => UnpublishEntry::class,
            'name' => 'entry.unpublish',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => CreateNote::class,
            'name' => 'fixture.note.create',
            'package' => RegistryFixtures::PACKAGE,
            'version' => 1,
        ], [
            'class' => SetArticleSlug::class,
            'name' => 'fixtureaddon.slug.set',
            'package' => FixtureAddonServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => AssignGrant::class,
            'name' => 'grant.assign',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => RevokeGrant::class,
            'name' => 'grant.revoke',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => CreatePlacement::class,
            'name' => 'placement.create',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => SetPlacementWindow::class,
            'name' => 'placement.set_window',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => CreateRole::class,
            'name' => 'role.create',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => SetRolePermissions::class,
            'name' => 'role.set_permissions',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => SeedEntries::class,
            'name' => 'seed.entries',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => RegisterSite::class,
            'name' => 'site.register',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ], [
            'class' => ReleaseVariant::class,
            'name' => 'variant.release',
            'package' => CoreServiceProvider::PACKAGE,
            'version' => 1,
        ]]]);
});

it('exits with 65 and prints the error code when two classes declare the same command and version', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    FixtureRootProvider::$fixture = 'DuplicateCommand';
    app()->register(FixtureRootProvider::class);

    [$status, $output] = buildCommand();

    expect($status)->toBe(BuildCommand::EXIT_INVALID_DECLARATIONS)
        ->and($status)->toBe(65)
        ->and($output[0])->toStartWith('[registry_duplicate_command] Command "x.y" version 1 is declared by ')
        ->and($output[1])->toBe('The registry was not built, and the cache was left as it was.')
        ->and(is_dir($directory))->toBeFalse();
});

it('exits with 73 when the cache cannot be written', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/bootstrap', '');
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory.'/bootstrap/cache/cms'));

    [$status, $output] = buildCommand();

    expect($status)->toBe(BuildCommand::EXIT_UNWRITABLE)
        ->and($output[0])->toStartWith('[registry_cache_unwritable] ');
});

it('prints the warnings of a build with their codes before the counts', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->instance(OpenApiDocuments::class, RegistryFixtures::documents($directory));
    app(Repository::class)->set('cbox-cms.addons.allowed', [WorkbenchServiceProvider::FIXTURE_ADDON, PanelBuildWorld::ADDON]);
    app(Repository::class)->set('cbox-cms.addons.publishers', [WorkbenchServiceProvider::FIXTURE_ADDON => [WorkbenchServiceProvider::FIXTURE_ADDON_PUBLISHER_KEY], PanelBuildWorld::ADDON => [PanelAddonProvider::PUBLISHER_KEY]]);
    app()->register(PanelAddonProvider::class);

    [$status, $output] = buildCommand();

    $warnings = array_values(array_filter($output, static fn (string $line): bool => str_starts_with($line, '[registry_')));

    expect($status)->toBe(0)
        ->and($warnings)->toHaveCount(23)
        ->and($warnings[0])->toStartWith('[registry_panel_point_experimental] The contribution approvals.badge of addon "approvals" (acme/cms-approvals) contributes to notes.detail.sections@1')
        ->and($warnings[1])->toStartWith('[registry_panel_point_deprecated] The contribution approvals.legacy of addon "approvals" (acme/cms-approvals) contributes to notes.legacy@1')
        ->and(array_slice($warnings, 2))->toBe(fixtureAddonWarnings())
        ->and($output[23])->toStartWith('actions: ')
        ->and($output)->toContain('addons: 2')
        ->and($output)->toContain('panel: 31');
});

it('refuses an installed addon the allowlist does not name, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->instance(OpenApiDocuments::class, RegistryFixtures::documents($directory));
    app(Repository::class)->set('cbox-cms.addons.allowed', []);

    [$status, $output] = buildCommand();

    expect($status)->toBe(BuildCommand::EXIT_INVALID_DECLARATIONS)
        ->and($output[0])->toStartWith('[registry_addon_not_allowed] Addon "fixtureaddon" (cboxdk/cms-fixture-addon) is installed, and the installation\'s allowlist of addons does not name cboxdk/cms-fixture-addon (PRD 13.8).')
        ->and(is_dir($directory))->toBeFalse();
});
