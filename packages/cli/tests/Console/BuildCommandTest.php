<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Console\BuildCommand;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
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

it('writes the five registries to the application\'s bootstrap/cache/cms, and removes the files it no longer writes', function (): void {
    $directory = app()->bootstrapPath('cache/cms');

    if (! is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    file_put_contents($directory.'/slots.php', "<?php return ['entries' => [], 'format' => 1, 'registry' => 'slots'];\n");

    [$status, $output] = buildCommand();

    expect($status)->toBe(0)
        ->and($output)->toBe([
            'actions: 2',
            'commands: 2',
            'hooks: 0',
            'schema: 0',
            'subscribers: 0',
            sprintf('Registry written to %s.', $directory),
        ])
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php', 'schema.php', 'subscribers.php']);
});

it('adds what an addon provider\'s scan root declares', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->register(FixtureRootProvider::class);

    [$status, $output] = buildCommand();

    expect($status)->toBe(0)
        ->and(array_slice($output, 0, 3))->toBe(['actions: 4', 'commands: 3', 'hooks: 1'])
        ->and(registryEntries(RegistryFixtures::load($directory.'/commands.php')))->toContain([
            'class' => CreateNote::class,
            'name' => 'fixture.note.create',
            'package' => RegistryFixtures::PACKAGE,
            'version' => 1,
        ]);
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

/**
 * The entries of a loaded registry file, or none when it is not an array with entries.
 *
 * @return array<mixed>
 */
function registryEntries(mixed $registry): array
{
    return is_array($registry) && is_array($registry['entries'] ?? null) ? $registry['entries'] : [];
}
