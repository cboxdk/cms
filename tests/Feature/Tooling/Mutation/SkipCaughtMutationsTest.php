<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\RecordedCommand;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Mutation\Adapter\SkipCaughtMutations;
use Cbox\Cms\Tooling\Mutation\Domain\CaughtByFastSuites;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationLedger;
use Cbox\Cms\Tooling\Mutation\Domain\MutationOutcome;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

/*
 * The step with Postgres counts a mutation the fast suites caught as caught from their ledger, so
 * running it again cannot change the score. CaughtByFastSuites writes those mutations to a file
 * when the step runs, and the report plugin leaves them out of the Postgres suite's run
 * (SkipCaughtMutations), which then runs only the mutations the fast suites did not catch.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
    putenv(CaughtByFastSuites::VARIABLE);
});

function skippedSource(): ChangedSource
{
    return new ChangedSource('packages/core/src/Registry/Adapter/FileRegistryCache.php', FileRegistryCache::class);
}

function skippedLedger(): MutationLedger
{
    $ledger = new MutationLedger;
    $ledger->record([
        skippedSource()->path => [new MutationOutcome('b2', true, 1, TrueToFalse::class), new MutationOutcome('a1', true, 1, TrueToFalse::class), new MutationOutcome('c3', false, 1, TrueToFalse::class)],
        'packages/core/src/Other/Domain/Other.php' => [new MutationOutcome('d4', true, 1, TrueToFalse::class)],
    ]);

    return $ledger;
}

function mutationOf(string $path, string $id): Mutation
{
    return new Mutation(new SplFileInfo(Phpstan::root().'/'.$path, dirname($path), $path), $id, 'TrueToFalse', 1, 1, '', '/dev/null');
}

it('lists the mutations of its sources the fast suites caught, and writes them for the Pest run when the step runs', function (): void {
    $precheck = new CaughtByFastSuites([skippedSource()], skippedLedger());
    $directory = ScratchDirectory::make();

    expect($precheck->passedWithout())->toBeNull()
        ->and($precheck->caught())->toBe([skippedSource()->path => ['a1', 'b2']])
        ->and($precheck->prepare($directory))->toBe([CaughtByFastSuites::VARIABLE => CaughtByFastSuites::CAUGHT_FILE])
        ->and(file_get_contents($directory.'/'.CaughtByFastSuites::CAUGHT_FILE))->toBe('{"files":{"packages/core/src/Registry/Adapter/FileRegistryCache.php":["a1","b2"]}}');
});

it('hands the prepared variables to the step\'s command once its precheck lets it run', function (): void {
    $runner = ScriptedProcessRunner::passing();
    $directory = ScratchDirectory::make();
    $step = Step::run('Mutation on changed files, with Postgres', ['pest'], environment: ['A' => '1'], precheck: new CaughtByFastSuites([skippedSource()], skippedLedger()));

    new CheckRunner($runner, new MutationListener)->run([new Gate(5, 'Pest', [$step])], $directory);
    $environments = array_map(static fn (RecordedCommand $call): array => $call->environment, $runner->calls);

    expect($environments)->toHaveCount(1)
        ->and($environments[0]['A'] ?? null)->toBe('1')
        ->and($environments[0][CaughtByFastSuites::VARIABLE] ?? null)->toBe(CaughtByFastSuites::CAUGHT_FILE)
        ->and(is_file($directory.'/'.CaughtByFastSuites::CAUGHT_FILE))->toBeTrue();
});

it('removes the caught mutations from the suite before they run and keeps every other', function (): void {
    $suite = new MutationSuite;
    $other = 'tools/src/Mutation/Domain/MutationCount.php';

    foreach ([[skippedSource()->path, 'a1'], [skippedSource()->path, 'b2'], [skippedSource()->path, 'c3'], [$other, 'a1']] as [$path, $id]) {
        $suite->repository->add(mutationOf($path, $id));
    }

    $removed = new SkipCaughtMutations([skippedSource()->path => ['a1', 'b2'], 'packages/gone/src/Gone.php' => ['z9']])->skip($suite);
    $left = [];

    foreach ($suite->repository->all() as $collection) {
        foreach ($collection->tests() as $test) {
            $left[] = [str_replace(Phpstan::root().'/', '', (string) $collection->file->getRealPath()), $test->getId()];
        }
    }

    expect($removed)->toBe(2)
        ->and($left)->toBe([[skippedSource()->path, 'c3'], [$other, 'a1']])
        ->and(array_map(static fn (MutationTest $test): string => $test->getId(), array_first($suite->repository->all())?->tests() ?? []))->toBe(['c3']);
});

it('reads the file the variable names, and nothing without the variable', function (): void {
    expect(SkipCaughtMutations::fromEnvironment())->toBeNull();

    $directory = ScratchDirectory::make();
    new CaughtByFastSuites([skippedSource()], skippedLedger())->prepare($directory);
    putenv(CaughtByFastSuites::VARIABLE.'='.$directory.'/'.CaughtByFastSuites::CAUGHT_FILE);
    $suite = new MutationSuite;
    $suite->repository->add(mutationOf(skippedSource()->path, 'a1'));

    expect(SkipCaughtMutations::fromEnvironment()?->skip($suite))->toBe(1);

    ScratchDirectory::write($directory.'/broken.json', '{"files":{"a.php":[1]}}');
    putenv(CaughtByFastSuites::VARIABLE.'='.$directory.'/broken.json');

    expect(static fn (): ?SkipCaughtMutations => SkipCaughtMutations::fromEnvironment())->toThrow(RuntimeException::class, 'does not list the caught mutations');
});
