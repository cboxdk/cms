<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Mutation\Adapter\CheckoutGuard;
use Symfony\Component\Process\Process;

/*
 * A mutation step's Pest process removes, when it ends, the files a mutation wrote into the
 * checkout, such as the registry cache a mutation of FileRegistryCache wrote into the root, and
 * leaves every file that was there when it started, tracked, ignored or not.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A scratch git checkout with a tracked file, an ignored file and an untracked file.
 */
function guardedCheckout(): string
{
    $root = ScratchDirectory::make();
    ScratchDirectory::write($root.'/.gitignore', "ignored.php\n");
    ScratchDirectory::write($root.'/tracked.php', "<?php\n");

    foreach ([['init', '-q'], ['add', '.gitignore', 'tracked.php']] as $arguments) {
        new Process(['git', ...$arguments], $root)->mustRun();
    }

    ScratchDirectory::write($root.'/untracked.php', "<?php\n");

    return $root;
}

it('removes the untracked files that appeared after it started and keeps every other file', function (): void {
    $root = guardedCheckout();
    $guard = CheckoutGuard::start($root);
    ScratchDirectory::write($root.'/actions.php', "<?php\n");
    ScratchDirectory::write($root.'/ignored.php', "<?php\n");
    mkdir($root.'/nested');
    ScratchDirectory::write($root.'/nested/openapi.json', '{}');

    $removed = $guard?->removeStrays();

    expect($removed)->toBe(['actions.php', 'nested/openapi.json'])
        ->and(is_file($root.'/actions.php'))->toBeFalse()
        ->and(is_file($root.'/nested/openapi.json'))->toBeFalse()
        ->and(is_file($root.'/untracked.php'))->toBeTrue()
        ->and(is_file($root.'/ignored.php'))->toBeTrue()
        ->and(is_file($root.'/tracked.php'))->toBeTrue()
        ->and($guard?->removeStrays())->toBe([]);
});

it('guards nothing outside a git checkout', function (): void {
    expect(CheckoutGuard::start(ScratchDirectory::make()))->toBeNull();
});

it('gives the files of the second list that the first does not hold, sorted', function (): void {
    expect(CheckoutGuard::strays(['a.php', 'c.php'], ['d.php', 'a.php', 'b.php', 'c.php']))->toBe(['b.php', 'd.php'])
        ->and(CheckoutGuard::strays(['a.php'], ['a.php']))->toBe([]);
});
