<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * cms:build as composer and a developer run it (PRD 13.2): from the command line of the testbench
 * application, after every dump-autoload, into a cache directory that git ignores.
 */

/**
 * Where the testbench application keeps the registry, relative to the monorepo root.
 */
const REGISTRY_CACHE = 'vendor/orchestra/testbench-core/laravel/bootstrap/cache/cms';

/**
 * @return array<string, string> sha256 by file name
 */
function registryHashes(): array
{
    $hashes = [];

    foreach (glob(Phpstan::root().'/'.REGISTRY_CACHE.'/*') ?: [] as $file) {
        $hashes[basename($file)] = (string) hash_file('sha256', $file);
    }

    ksort($hashes);

    return $hashes;
}

function testbenchBuild(): Process
{
    $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'cms:build', '--no-ansi'], Phpstan::root(), null, null, 120);
    $process->run();

    return $process;
}

it('runs cms:build after composer has discovered the providers on every dump-autoload', function (): void {
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $line = array_values(preg_grep('/post-autoload-dump/', file(Phpstan::root().'/composer.json') ?: []) ?: []);

    $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

    expect($scripts['post-autoload-dump'] ?? null)->toBe(['@clear', '@prepare', '@php vendor/bin/testbench cms:build --ansi'])
        ->and($line)->toHaveCount(1)
        ->and($line[0] ?? '')->toContain('cms:build');
});

it('builds the six files from the command line, byte for byte the same each time', function (): void {
    $first = testbenchBuild();
    $firstHashes = registryHashes();
    $second = testbenchBuild();

    expect($first->getExitCode())->toBe(0, $first->getErrorOutput().$first->getOutput())
        ->and($first->getOutput())->toContain('Registry written to')
        ->and(array_keys($firstHashes))->toBe(['actions.php', 'commands.php', 'hooks.php', 'schema.php', 'slots.php', 'subscribers.php'])
        ->and($second->getExitCode())->toBe(0)
        ->and(registryHashes())->toBe($firstHashes);
});

it('keeps the cache out of git', function (): void {
    $process = new Process(['git', 'check-ignore', '--quiet', REGISTRY_CACHE.'/actions.php'], Phpstan::root());
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
