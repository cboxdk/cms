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
use Cbox\Cms\Core\Tests\Registry\Providers\FixtureRootProvider;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Illuminate\Contracts\Console\Kernel;

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

it('is registered', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:build')
        ->and(app(Kernel::class)->all()['cms:build'])->toBeInstanceOf(BuildCommand::class);
});

it('writes the six registries to the application\'s bootstrap/cache/cms, and removes the files it no longer writes', function (): void {
    $directory = app()->bootstrapPath('cache/cms');

    if (! is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    file_put_contents($directory.'/slots.php', "<?php return ['entries' => [], 'format' => 1, 'registry' => 'slots'];\n");

    [$status, $output] = buildCommand();

    expect($status)->toBe(0)
        ->and($output)->toBe([
            'actions: 22',
            'commands: 17',
            // The workbench's fixture addon, which package discovery registers: its two hooks and
            // its extension of app:fixture_article.
            'hooks: 2',
            'rest: 16',
            'schema: 1',
            'subscribers: 1',
            sprintf('Registry written to %s.', $directory),
        ])
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php', 'openapi.json', 'rest.php', 'schema.php', 'subscribers.php']);
});

it('adds what an addon provider\'s scan root declares', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->instance(OpenApiDocuments::class, RegistryFixtures::documents($directory));
    app()->register(FixtureRootProvider::class);

    [$status, $output] = buildCommand();

    expect($status)->toBe(0)
        ->and(array_slice($output, 0, 4))->toBe(['actions: 24', 'commands: 18', 'hooks: 3', 'rest: 17'])
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
