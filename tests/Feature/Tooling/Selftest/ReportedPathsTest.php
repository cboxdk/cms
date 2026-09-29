<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Selftest;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Selftest\Domain\ReportedPaths;

/*
 * How the selftest reads a path out of a tool's output and decides whether it leads to the
 * planted file inside the worktree. Each case is the way one of the gate's tools names a file.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A worktree with one planted file and a vendor/ symlink into its own packages/, next to another
 * checkout with the same file, so a name that leads out of the worktree can be told apart.
 *
 * @return array{worktree: string, other: string}
 */
function reportedPathsLayout(): array
{
    $base = ScratchDirectory::make();
    $worktree = $base.'/worktree';
    $other = $base.'/other';

    foreach ([$worktree, $other] as $checkout) {
        ScratchDirectory::write($checkout.'/packages/core/src/Selftest/Domain/Plant.php', '<?php');
        mkdir($checkout.'/vendor/acme', 0o777, true);
        mkdir($checkout.'/vendor/composer', 0o777, true);
    }

    symlink('../../packages/core', $worktree.'/vendor/acme/linked-core');
    symlink($other.'/packages/core', $worktree.'/vendor/acme/linked-other');

    return ['worktree' => $worktree, 'other' => $other];
}

const PLANT = 'packages/core/src/Selftest/Domain/Plant.php';

it('finds the planted file however a tool names it', function (string $output): void {
    ['worktree' => $worktree] = reportedPathsLayout();
    $output = str_replace('{worktree}', $worktree, $output);

    $paths = ReportedPaths::search($output, PLANT, $worktree);

    expect($paths->isFound())->toBeTrue()
        ->and($paths->found)->toBe([$worktree.'/'.PLANT])
        ->and($paths->outside)->toBe([]);
})->with([
    'relative, as Pint, Prettier, Rector and PHPStan print it' => ['  Line   packages/core/src/Selftest/Domain/Plant.php'],
    'absolute, as ESLint prints it' => ["{worktree}/packages/core/src/Selftest/Domain/Plant.php\n  1:39  error"],
    'through the vendor symlink of a path repository, as Pest arch prints it' => ["at \e[32mvendor/composer/../acme/linked-core/src/Selftest/Domain/Plant.php\e[39m:\e[32m8\e[39m"],
    'through the root package\'s autoload path, as Pest arch prints it for cboxdk/cms' => ["at \e[32mvendor/composer/../../packages/core/src/Selftest/Domain/Plant.php\e[39m:\e[32m8\e[39m"],
    'JSON-escaped, as Pint prints it for agents' => ['{"files":[{"path":"packages\/core\/src\/Selftest\/Domain\/Plant.php"}]}'],
    'with a position, as tsc prints it' => ['packages/core/src/Selftest/Domain/Plant.php(1,14): error TS2322'],
    'with git\'s diff prefixes' => ["diff --git a/packages/core/src/Selftest/Domain/Plant.php b/packages/core/src/Selftest/Domain/Plant.php\n+++ b/packages/core/src/Selftest/Domain/Plant.php"],
]);

it('does not count a name that leads out of the worktree, and reports it', function (string $output): void {
    ['worktree' => $worktree, 'other' => $other] = reportedPathsLayout();
    $output = str_replace('{other}', $other, $output);

    $paths = ReportedPaths::search($output, PLANT, $worktree);

    expect($paths->isFound())->toBeFalse()
        ->and($paths->found)->toBe([])
        ->and($paths->outside)->toBe([$other.'/'.PLANT]);
})->with([
    'an absolute path in another checkout' => ['{other}/packages/core/src/Selftest/Domain/Plant.php'],
    'a vendor symlink into another checkout' => ['vendor/acme/linked-other/src/Selftest/Domain/Plant.php'],
]);

it('is not found when one name leads inside and another outside', function (): void {
    ['worktree' => $worktree, 'other' => $other] = reportedPathsLayout();

    $paths = ReportedPaths::search(PLANT."\n{$other}/".PLANT, PLANT, $worktree);

    expect($paths->found)->toHaveCount(1)
        ->and($paths->outside)->toHaveCount(1)
        ->and($paths->isFound())->toBeFalse();
});

it('ignores names that only contain the base name or lead nowhere', function (string $output): void {
    ['worktree' => $worktree] = reportedPathsLayout();

    $paths = ReportedPaths::search($output, PLANT, $worktree);

    expect($paths->found)->toBe([])
        ->and($paths->outside)->toBe([])
        ->and($paths->isFound())->toBeFalse();
})->with([
    'no path at all' => ['Found 0 errors'],
    'a longer base name' => ['packages/core/src/Selftest/Domain/APlant.php and Plant.php-old'],
    'the bare base name' => ['Plant.php'],
    'a path that does not exist' => ['packages/core/src/Elsewhere/Plant.php'],
]);

it('strips terminal colours and escaped slashes', function (): void {
    expect(ReportedPaths::normalize("\e[31;1mfail\e[39;22m a\/b"))->toBe('fail a/b')
        ->and(ReportedPaths::names('x packages/a/Plant.php:3 and (b/Plant.php)', 'Plant.php'))->toBe(['packages/a/Plant.php', 'b/Plant.php']);
});
