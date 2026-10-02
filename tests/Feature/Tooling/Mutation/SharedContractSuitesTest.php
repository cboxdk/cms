<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tooling\Mutation\Domain\SharedContractSuites;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/*
 * Mutation on changed files leaves out the testkit's shared contract suites and nothing else
 * (Sylvester's decision of 1 October, settling M0-T47). The list names exactly the traits named
 * *Contract in packages/testkit/src that a test class uses, each by its file, so no production
 * class can hide in it and no new suite is mutated by mistake.
 */

/**
 * The PHP files below $directory, repository-relative and sorted.
 *
 * @return list<string>
 */
function phpFilesBelow(string $root, string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($files);

    return $files;
}

/**
 * The kinds and names the code declares, such as "trait ClockContract", in order.
 *
 * @return list<string>
 */
function declarationsIn(string $code): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    $declared = [];

    foreach ($tokens as $index => $token) {
        $next = $tokens[$index + 1] ?? null;
        $previous = $tokens[$index - 1] ?? null;

        if ($token->is([T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT]) && $next?->is(T_STRING) && ! $previous?->is([T_DOUBLE_COLON, T_NEW])) {
            $declared[] = strtolower($token->text).' '.$next->text;
        }
    }

    return $declared;
}

/**
 * The traits named *Contract in packages/testkit/src that a test class below a tests directory
 * uses, by file.
 *
 * @return list<string>
 */
function sharedContractSuites(string $root): array
{
    $used = [];

    foreach (['tests', 'packages', 'examples', 'workbench'] as $directory) {
        foreach (phpFilesBelow($root, $directory) as $file) {
            if (str_contains($file, '/tests/') || str_starts_with($file, 'tests/') || str_starts_with($file, 'examples/')) {
                preg_match_all('/^\s+use\s+(\w+Contract)\s*;/m', (string) file_get_contents($root.'/'.$file), $uses);
                $used = [...$used, ...$uses[1]];
            }
        }
    }

    $suites = [];

    foreach (phpFilesBelow($root, 'packages/testkit/src') as $file) {
        foreach (declarationsIn((string) file_get_contents($root.'/'.$file)) as $declaration) {
            if (preg_match('/^trait (\w+Contract)$/', $declaration, $trait) === 1 && in_array($trait[1], $used, true)) {
                $suites[] = $file;
            }
        }
    }

    sort($suites);

    return $suites;
}

it('names exactly the testkit\'s shared contract suites, sorted', function (): void {
    $root = dirname(__DIR__, 4);

    expect(SharedContractSuites::PATHS)->toBe(sharedContractSuites($root));
});

it('names only files that declare one trait named *Contract and nothing else', function (string $path): void {
    $root = dirname(__DIR__, 4);

    expect(is_file($root.'/'.$path))->toBeTrue()
        ->and(declarationsIn((string) file_get_contents($root.'/'.$path)))->toBe(['trait '.basename($path, '.php')])
        ->and(basename($path, '.php'))->toEndWith('Contract')
        ->and($path)->toStartWith('packages/testkit/src/');
})->with(SharedContractSuites::PATHS);

it('leaves out a listed suite and no other path', function (): void {
    expect(SharedContractSuites::leavesOut('packages/testkit/src/Clock/ClockContract.php'))->toBeTrue()
        ->and(SharedContractSuites::leavesOut('packages/testkit/src/Clock/FakeClock.php'))->toBeFalse()
        ->and(SharedContractSuites::leavesOut('packages/core/src/Clock/ClockContract.php'))->toBeFalse()
        ->and(SharedContractSuites::leavesOut('/packages/testkit/src/Clock/ClockContract.php'))->toBeFalse();
});
