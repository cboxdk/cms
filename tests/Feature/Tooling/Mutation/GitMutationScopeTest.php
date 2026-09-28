<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tests\Support\Tooling\RecordedCommand;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Boundary\GitMutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;

/*
 * What mutation on changed files mutates, read from git on scratch repositories: the files below
 * packages/<package>/src added or changed since the merge base of CMS_CI_BASE_REF and HEAD, with
 * the class each declares. When CMS_CI_BASE_REF is unset, empty or 40 zeros, the base is derived:
 * HEAD~1 on main, the merge base with origin/main (or main) elsewhere, and the empty tree for the
 * first commit. An unknown base, or one that cannot be derived, fails the step with the reason,
 * never as an empty change.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A repository whose first commit has two classes of a package, one of them to be removed.
 */
function baseRepository(): ScratchRepository
{
    return ScratchRepository::make()
        ->write('packages/demo/src/Domain/Kept.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Kept {}\n")
        ->write('packages/demo/src/Domain/Removed.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Removed {}\n")
        ->write('README.md', "base\n");
}

/**
 * @return list<array{string, string}>
 */
function pathsAndNames(MutationScope $scope): array
{
    return array_map(static fn (ChangedSource $source): array => [$source->path, $source->name], $scope->sources);
}

/**
 * The base's reason for each value of CMS_CI_BASE_REF that names no base.
 *
 * @return array<string, array{string|null, string}>
 */
function missingBases(): array
{
    return [
        'unset' => [null, 'CMS_CI_BASE_REF is not set'],
        'empty' => ['', 'CMS_CI_BASE_REF is empty'],
        'blank' => ['  ', 'CMS_CI_BASE_REF is empty'],
        '40 zeros, the before of a push that creates a branch' => [str_repeat('0', 40), 'CMS_CI_BASE_REF is 40 zeros, the commit before a push that created the branch'],
    ];
}

/**
 * The changed sources, in the Pest runs the steps of mutation on changed files make of them.
 *
 * @return list<string>
 */
function mutatedPaths(MutationScope $scope): array
{
    $paths = [];

    foreach (MutationSteps::for($scope, '/usr/bin/php') as $step) {
        foreach ($step->command as $argument) {
            if (str_starts_with($argument, '--path=')) {
                $paths[] = substr($argument, strlen('--path='));
            }
        }
    }

    return $paths;
}

it('derives HEAD~1 as the base on main when CMS_CI_BASE_REF is unset, empty or 40 zeros, and mutates the classes the last commit changed', function (?string $ref, string $reason): void {
    $repository = baseRepository();
    $repository->commit('base');
    $repository->write('packages/demo/src/Domain/Kept.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Kept { public int \$n = 1; }\n")->write('README.md', "changed\n");
    $previous = $repository->git('rev-parse', 'HEAD');
    $repository->commit('main moves on');

    $scope = GitMutationScope::resolve($repository->root, $ref);

    expect($scope->failure)->toBeNull()
        ->and($scope->base)->toBe("{$previous}, HEAD~1 of main, as {$reason} and HEAD is main")
        ->and(pathsAndNames($scope))->toBe([['packages/demo/src/Domain/Kept.php', 'Acme\Demo\Domain\Kept']])
        ->and(mutatedPaths($scope))->toBe(['packages/demo/src/Domain/Kept.php']);
})->with(missingBases());

it('derives the merge base with origin/main as the base on another branch, before a local main, when CMS_CI_BASE_REF names none', function (?string $ref, string $reason): void {
    $repository = baseRepository();
    $fork = $repository->commit('base');
    $repository->git('update-ref', 'refs/remotes/origin/main', $fork);
    $repository->git('checkout', '--quiet', '-b', 'feature');
    $repository->write('packages/demo/src/Domain/Added.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Added {}\n")->commit('feature');
    // The local main already has the feature, as after a merge that was not pushed; origin/main has not.
    $repository->git('branch', '--force', 'main', 'feature');

    $scope = GitMutationScope::resolve($repository->root, $ref);

    expect($scope->failure)->toBeNull()
        ->and($scope->base)->toBe("{$fork}, the merge base of origin/main and HEAD, as {$reason} and HEAD is not main")
        ->and(pathsAndNames($scope))->toBe([['packages/demo/src/Domain/Added.php', 'Acme\Demo\Domain\Added']])
        ->and(mutatedPaths($scope))->toBe(['packages/demo/src/Domain/Added.php']);
})->with(missingBases());

it('derives the merge base with main on another branch or a detached HEAD in a repository without origin/main', function (bool $detached): void {
    $repository = baseRepository();
    $fork = $repository->commit('base');
    $repository->git('checkout', '--quiet', '-b', 'feature');
    $repository->write('packages/demo/src/Domain/Kept.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Kept { public int \$n = 1; }\n")->commit('feature');
    // main moves on after the fork; its change is not the feature's.
    $repository->git('checkout', '--quiet', 'main');
    $repository->write('packages/demo/src/Domain/OnMain.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class OnMain {}\n")->commit('main moves on');
    $repository->git('checkout', '--quiet', ...($detached ? ['--detach', 'feature'] : ['feature']));

    $scope = GitMutationScope::resolve($repository->root, null);

    expect($scope->failure)->toBeNull()
        ->and($scope->base)->toBe("{$fork}, the merge base of main and HEAD, as CMS_CI_BASE_REF is not set and HEAD is not main")
        ->and(pathsAndNames($scope))->toBe([['packages/demo/src/Domain/Kept.php', 'Acme\Demo\Domain\Kept']]);
})->with(['a branch' => false, 'a detached HEAD' => true]);

it('counts every file as changed in a repository with one commit, on main or another branch', function (string $branch, ?string $ref, string $reason): void {
    $repository = ScratchRepository::make();
    $repository->git('symbolic-ref', 'HEAD', 'refs/heads/'.$branch);
    $head = $repository
        ->write('packages/demo/src/Domain/Kept.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Kept {}\n")
        ->write('packages/demo/src/Store/Adapter/PostgresStore.php', "<?php\n\nnamespace Acme\\Demo\\Store\\Adapter;\n\nfinal class PostgresStore {}\n")
        ->write('README.md', "only commit\n")
        ->commit('the only commit');

    $scope = GitMutationScope::resolve($repository->root, $ref);

    expect($scope->failure)->toBeNull()
        ->and($scope->base)->toBe("the empty tree, as {$reason} and HEAD {$head} is the only commit of the repository, so every file counts as changed")
        ->and(pathsAndNames($scope))->toBe([
            ['packages/demo/src/Domain/Kept.php', 'Acme\Demo\Domain\Kept'],
            ['packages/demo/src/Store/Adapter/PostgresStore.php', 'Acme\Demo\Store\Adapter\PostgresStore'],
        ])
        ->and(mutatedPaths($scope))->toBe(['packages/demo/src/Domain/Kept.php,packages/demo/src/Store/Adapter/PostgresStore.php', 'packages/demo/src/Store/Adapter/PostgresStore.php']);
})->with([
    'main, unset' => ['main', null, 'CMS_CI_BASE_REF is not set'],
    'main, 40 zeros' => ['main', str_repeat('0', 40), 'CMS_CI_BASE_REF is 40 zeros, the commit before a push that created the branch'],
    'another branch, empty' => ['trunk', '', 'CMS_CI_BASE_REF is empty'],
]);

it('counts every file as changed when HEAD is the first commit of main in a repository with other commits', function (): void {
    $repository = baseRepository();
    $repository->commit('base');
    $repository->git('branch', 'old-main');
    $repository->git('checkout', '--quiet', '--orphan', 'main-again');
    $repository->git('rm', '--quiet', '-r', '--cached', '.');
    $repository->git('clean', '--quiet', '-fdx');
    $repository->write('packages/other/src/Thing.php', "<?php\n\nnamespace Acme\\Other;\n\nfinal class Thing {}\n");
    $head = $repository->commit('a new root');
    $repository->git('branch', '--quiet', '--move', '--force', 'main-again', 'main');

    $scope = GitMutationScope::resolve($repository->root, null);

    expect($scope->failure)->toBeNull()
        ->and($scope->base)->toBe("the empty tree, as CMS_CI_BASE_REF is not set and HEAD {$head} is the first commit of main, so every file counts as changed")
        ->and(pathsAndNames($scope))->toBe([['packages/other/src/Thing.php', 'Acme\Other\Thing']]);
});

it('fails the step of the PR profile with the reason, and mutates nothing, when CMS_CI_BASE_REF names no base and none can be derived', function (?string $ref, string $reason): void {
    $repository = ScratchRepository::make();
    $repository->git('symbolic-ref', 'HEAD', 'refs/heads/trunk');
    $repository->write('packages/demo/src/Domain/Kept.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class Kept {}\n")->commit('first');
    $repository->write('README.md', "second\n")->commit('second');
    $runner = ScriptedProcessRunner::passing();

    $scope = GitMutationScope::resolve($repository->root, $ref);
    $gate5 = PrProfile::gates('/usr/bin/php', ['/usr/bin/php', '/usr/bin/composer'], $scope)[4];
    $report = new CheckRunner($runner, new GitScopeListener)->run([$gate5], $repository->root);
    $step = $report->gate(5)?->step(MutationSteps::NAME);

    expect($scope->failure)->toBe("{$reason}, HEAD is not main, and neither origin/main nor main names a commit in {$repository->root} to take the merge base with. CI sets it to the base of the pull request.")
        ->and($scope->sources)->toBe([])
        ->and($step?->status)->toBe(StepStatus::Fail)
        ->and($step?->reason)->toContain($reason)
        ->and($step?->notes)->not->toContain(MutationSteps::NO_CHANGES)
        ->and($report->failedGates())->toBe([5])
        ->and(array_filter($runner->calls, static fn (RecordedCommand $call): bool => in_array('--mutate', $call->command, true)))->toBe([]);
})->with(missingBases());

it('fails with the reason when the derived mainline has no merge base with HEAD', function (): void {
    $repository = baseRepository();
    $repository->commit('base');
    $repository->git('checkout', '--quiet', '--orphan', 'unrelated');
    $repository->git('rm', '--quiet', '-r', '--cached', '.');
    $repository->write('packages/other/src/Thing.php', "<?php\n\nnamespace Acme\\Other;\n\nfinal class Thing {}\n")->commit('unrelated history');

    $scope = GitMutationScope::resolve($repository->root, str_repeat('0', 40));

    expect($scope->failure)->toStartWith("CMS_CI_BASE_REF is 40 zeros, the commit before a push that created the branch, and main has no merge base with HEAD in {$repository->root}: ")
        ->and($scope->sources)->toBe([]);
});

it('fails with the reason in a repository without a commit', function (): void {
    $repository = ScratchRepository::make();

    $scope = GitMutationScope::resolve($repository->root, null);

    expect($scope->failure)->toStartWith("CMS_CI_BASE_REF is not set, and HEAD names no commit in {$repository->root} to derive the base from: ")
        ->and($scope->sources)->toBe([]);
});

it('takes a ref of zeros that is not 40 long as a ref to resolve', function (): void {
    $repository = baseRepository();
    $repository->commit('base');

    $scope = GitMutationScope::resolve($repository->root, str_repeat('0', 39));

    expect($scope->failure)->toStartWith('CMS_CI_BASE_REF='.str_repeat('0', 39)." names no commit in {$repository->root}: ");
});

it('fails the step with the ref in the reason when CMS_CI_BASE_REF names no commit', function (): void {
    $repository = baseRepository();
    $repository->commit('base');

    $scope = GitMutationScope::resolve($repository->root, 'origin/no-such-branch');
    $gate5 = PrProfile::gates('/usr/bin/php', ['/usr/bin/php', '/usr/bin/composer'], $scope)[4];
    $step = new CheckRunner(ScriptedProcessRunner::passing(), new GitScopeListener)->run([$gate5], $repository->root)->gate(5)?->step(MutationSteps::NAME);

    expect($scope->failure)->toStartWith("CMS_CI_BASE_REF=origin/no-such-branch names no commit in {$repository->root}: ")
        ->and($step?->status)->toBe(StepStatus::Fail)
        ->and($step?->reason)->toContain('CMS_CI_BASE_REF=origin/no-such-branch');
});

it('fails with the ref in the reason when the ref has no merge base with HEAD', function (): void {
    $repository = baseRepository();
    $repository->commit('base');
    $repository->git('checkout', '--quiet', '--orphan', 'unrelated');
    $repository->git('rm', '--quiet', '-r', '--cached', '.');
    $repository->write('packages/other/src/Thing.php', "<?php\n\nnamespace Acme\\Other;\n\nfinal class Thing {}\n")->commit('unrelated history');

    $scope = GitMutationScope::resolve($repository->root, 'main');

    expect($scope->failure)->toStartWith("CMS_CI_BASE_REF=main has no merge base with HEAD in {$repository->root}: ")
        ->and($scope->sources)->toBe([]);
});

it('finds the added and changed files below packages/*/src since the merge base, with the class, enum or trait each declares', function (): void {
    $repository = baseRepository();
    $repository->commit('base');
    $repository->git('checkout', '--quiet', '-b', 'feature');
    $repository
        ->write('packages/demo/src/Domain/Kept.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\n// Kept::class is not a declaration.\nfinal class Kept\n{\n    public function name(): string\n    {\n        return self::class;\n    }\n}\n")
        ->write('packages/demo/src/Store/Adapter/PostgresStore.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Demo\\Store\\Adapter;\n\nuse Acme\\Demo\\Domain\\Kept;\n\nfinal readonly class PostgresStore\n{\n    public function make(): object\n    {\n        return new class {};\n    }\n}\n")
        ->write('packages/demo/src/Domain/Colour.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nenum Colour: string\n{\n    case Red = 'red';\n}\n")
        ->write('packages/demo/src/Concerns/Named.php', "<?php\n\nnamespace Acme\\Demo\\Concerns;\n\ntrait Named {}\n")
        ->write('packages/demo/src/helpers.php', "<?php\n\nfunction demo(): int\n{\n    return 1;\n}\n")
        ->write('packages/demo/tests/KeptTest.php', "<?php\n")
        ->write('packages/demo/src/notes.txt', "not PHP\n")
        ->write('README.md', "changed\n")
        ->delete('packages/demo/src/Domain/Removed.php')
        ->commit('feature');
    // A change on main after the fork is not the feature's.
    $repository->git('checkout', '--quiet', 'main');
    $repository->write('packages/demo/src/Domain/OnMain.php', "<?php\n\nnamespace Acme\\Demo\\Domain;\n\nfinal class OnMain {}\n")->commit('main moves on');
    $fork = $repository->git('merge-base', 'main', 'feature');
    $repository->git('checkout', '--quiet', 'feature');

    $scope = GitMutationScope::resolve($repository->root, 'main');

    expect($scope->failure)->toBeNull()
        ->and($scope->base)->toBe("{$fork}, the merge base of CMS_CI_BASE_REF=main and HEAD")
        ->and(pathsAndNames($scope))->toBe([
            ['packages/demo/src/Concerns/Named.php', 'Acme\Demo\Concerns\Named'],
            ['packages/demo/src/Domain/Colour.php', 'Acme\Demo\Domain\Colour'],
            ['packages/demo/src/Domain/Kept.php', 'Acme\Demo\Domain\Kept'],
            ['packages/demo/src/Store/Adapter/PostgresStore.php', 'Acme\Demo\Store\Adapter\PostgresStore'],
            ['packages/demo/src/helpers.php', 'packages/demo/src/helpers.php'],
        ])
        ->and(array_map(static fn (ChangedSource $source): bool => $source->needsPostgres(), $scope->sources))->toBe([false, false, false, true, false]);
});

it('gives 0 changed classes when only files outside packages/*/src changed, and names the merge base', function (): void {
    $repository = baseRepository();
    $base = $repository->commit('base');
    $repository->write('README.md', "changed\n")->write('packages/demo/tests/KeptTest.php', "<?php\n")->commit('docs and tests');

    $scope = GitMutationScope::resolve($repository->root, 'HEAD~1');
    $steps = MutationSteps::for($scope, '/usr/bin/php');

    expect($scope->failure)->toBeNull()
        ->and($scope->sources)->toBe([])
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]->decided)->toBe(StepStatus::Pass)
        ->and($steps[0]->decision)->toBe("0 changed classes since {$base}, the merge base of CMS_CI_BASE_REF=HEAD~1 and HEAD");
});
