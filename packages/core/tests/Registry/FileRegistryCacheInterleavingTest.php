<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Cbox\Cms\Core\Tests\Registry\Fakes\InterleavedFiles;
use Closure;
use PHPUnit\Framework\Assert;

/*
 * A cms:build replaces the registry files one at a time while requests and workers read them. The
 * steps below rename the files of a new build into place between the files a read loads, in the
 * order a build renames them (actions, commands, hooks). A read loads actions.php, commands.php
 * and hooks.php as opens 1, 2 and 3, and a second attempt as 4, 5 and 6. Whatever the order, a
 * read gives the whole old registry or the whole new one, never a mix of the two builds.
 */

afterEach(function (): void {
    InterleavedFiles::reset();
    RegistryFixtures::cleanUp();
});

function interleavedOld(): CompiledRegistry
{
    return new RegistryCompiler()->compile(RegistryFixtures::validDiscovery());
}

/**
 * A cache of the old registry, read through InterleavedFiles, and a finished build of the new
 * (empty) registry whose files the steps rename into place.
 *
 * @param  array<int, list<RegistryName>>  $renames  the files renamed into place before each open
 */
function interleavedRead(array $renames): CompiledRegistry
{
    $directory = RegistryFixtures::scratch();
    $next = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(interleavedOld());
    RegistryFixtures::cache($next)->write(CompiledRegistry::empty());

    $steps = array_map(static fn (array $names): Closure => static function () use ($names, $directory, $next): void {
        foreach ($names as $name) {
            Assert::assertTrue(copy($next.'/'.$name->fileName(), $directory.'/'.$name->fileName().'.0123456789abcdef.tmp'));
            Assert::assertTrue(rename($directory.'/'.$name->fileName().'.0123456789abcdef.tmp', $directory.'/'.$name->fileName()));
        }
    }, $renames);

    return RegistryFixtures::cache(InterleavedFiles::over($directory, $steps))->read();
}

/**
 * A dataset's renames, typed.
 *
 * @param  array<array-key, mixed>  $renames
 * @return array<int, list<RegistryName>>
 */
function interleavedRenames(array $renames): array
{
    $typed = [];

    foreach ($renames as $open => $names) {
        Assert::assertIsInt($open);
        Assert::assertIsArray($names);
        $typed[$open] = [];

        foreach ($names as $name) {
            Assert::assertInstanceOf(RegistryName::class, $name);
            $typed[$open][] = $name;
        }
    }

    return $typed;
}

it('reads the whole old or the whole new registry while a build renames its files', function (array $renames, string $expected): void {
    $read = interleavedRead(interleavedRenames($renames));

    expect($read)->toEqual($expected === 'old' ? interleavedOld() : CompiledRegistry::empty());
})->with([
    'the build ends before the read' => [[1 => RegistryName::cases()], 'new'],
    'the build starts after the read' => [[4 => RegistryName::cases()], 'old'],
    'the build lands after the actions are read' => [[2 => RegistryName::cases()], 'new'],
    'the build lands after the commands are read' => [[3 => RegistryName::cases()], 'new'],
    'each file is renamed just after it is read' => [[2 => [RegistryName::Actions], 3 => [RegistryName::Commands], 4 => [RegistryName::Hooks]], 'old'],
    'the actions are renamed before the read and the rest after it' => [[1 => [RegistryName::Actions], 4 => [RegistryName::Commands, RegistryName::Hooks]], 'new'],
    'the hooks are renamed while the read looks again' => [[1 => [RegistryName::Actions, RegistryName::Commands], 5 => [RegistryName::Hooks]], 'new'],
    'the commands are renamed between the actions and the hooks' => [[2 => [RegistryName::Actions, RegistryName::Commands], 4 => [RegistryName::Hooks]], 'new'],
]);

it('refuses files from two builds that stay mixed, as a build that stopped halfway leaves them', function (): void {
    try {
        $read = interleavedRead([1 => [RegistryName::Actions]]);
        Assert::fail(sprintf('A registry mixed from two builds was read, with %d commands and %d hooks.', count($read->commands), count($read->hooks)));
    } catch (MalformedRegistryCache $malformed) {
        expect($malformed->getMessage())
            ->toStartWith('[registry_cache_malformed] The registry cache file ')
            ->toContain('commands.php is not valid at build: it comes from another cms:build than actions.php')
            ->toContain('run php artisan cms:build');
    }

    expect(InterleavedFiles::opens())->toBeGreaterThan(3);
});
