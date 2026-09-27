<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Progress;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Cbox\Cms\Tooling\Progress\Boundary\GitEmptyCommits;
use Cbox\Cms\Tooling\Progress\Boundary\ProgressCheckOptions;
use Cbox\Cms\Tooling\Progress\Domain\EmptyCommit;
use Cbox\Cms\Tooling\Progress\Domain\ProgressAudit;
use Cbox\Cms\Tooling\Progress\Domain\ProgressLedger;
use Cbox\Cms\Tooling\Progress\Domain\TaskId;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * `composer progress:check` (tools/bin/progress-check.php): whether PROGRESS.md records a task
 * before the merge queue of .claude/workflows/cms-milestone.js moves main. The tasks merged through
 * the queue from M0-T40 to M0-T78 changed checks that PROGRESS.md never recorded, and M0-T77 landed
 * empty commits that said their PROGRESS.md entries were handed to the integration step, which
 * never wrote them. The script's parts are tested here on scratch text and a scratch repository,
 * the script on this checkout, and the merge queue's prompts on the workflow file.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

const PROGRESS_SAMPLE = <<<'MD'
    # Fremdrift

    ## Tolkninger

    - M1-T3: a reading.

    ## Til review af Sylvester

    Changes of checks:

    - M1-T3: Ændrede testforventninger (GUARDRAILS 7.3): `FooTest` expects bar,
      because the format changed.
    - M1-T4: a question about the naming.

    ## Kontroller kørt

    - 2026-10-01, M1-T3: `composer check`: gates 1 to 6 pass.
    - 2026-10-01, M1-T4, after commit: `composer check:selftest` exit 0.
    MD;

/**
 * Runs tools/bin/progress-check.php of this checkout with the arguments.
 *
 * @return array{int, string}
 */
function runProgressCheck(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/progress-check.php', ...array_values($arguments)], Phpstan::root());
    $process->run();

    return [$process->getExitCode() ?? -1, $process->getOutput().$process->getErrorOutput()];
}

it('reads the entries of each section, with their continuation lines and without the section\'s introduction', function (): void {
    $ledger = ProgressLedger::fromMarkdown(PROGRESS_SAMPLE);

    expect($ledger->hasSection(ProgressLedger::REVIEW))->toBeTrue()
        ->and($ledger->hasSection('Blokeret'))->toBeFalse()
        ->and($ledger->entries(ProgressLedger::REVIEW))->toBe([
            'M1-T3: Ændrede testforventninger (GUARDRAILS 7.3): `FooTest` expects bar, because the format changed.',
            'M1-T4: a question about the naming.',
        ])
        ->and($ledger->entries(ProgressLedger::CHECKS_RUN))->toHaveCount(2)
        ->and($ledger->entries('Blokeret'))->toBe([]);
});

it('names a task only where its id stands on its own', function (string $text, bool $named): void {
    expect(new TaskId('M0-T41')->namedIn($text))->toBe($named);
})->with([
    ['- M0-T41: text', true],
    ['2026-09-26, M0-T41, after commit', true],
    ['the posts of M0-T41 and M0-T42', true],
    ['M0-T41a: text', false],
    ['M0-T410: text', false],
    ['XM0-T41: text', false],
    ['AM0-T41', false],
]);

it('refuses a task id that is not <block>-<task>', function (string $id): void {
    expect(static fn (): TaskId => new TaskId($id))->toThrow(InvalidArgumentException::class, '<block>-<task>');
})->with(['', 'T43', 'm0-T43', 'M0-', 'M0 T43', 'M0-T43;rm']);

it('finds nothing to fault when the task has its gate runs and its GUARDRAILS 7.3 entry', function (): void {
    $ledger = ProgressLedger::fromMarkdown(PROGRESS_SAMPLE);

    expect(ProgressAudit::problems($ledger, new TaskId('M1-T3'), true, []))->toBe([])
        ->and(ProgressAudit::problems($ledger, new TaskId('M1-T4'), false, []))->toBe([]);
});

it('requires an entry under Kontroller kørt for every task', function (): void {
    $problems = ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), new TaskId('M1-T5'), false, []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith('PROGRESS.md has no entry for M1-T5 under "## Kontroller kørt".');
});

it('requires a GUARDRAILS 7.3 entry under Til review af Sylvester when the task changed checks, and no other entry will do', function (): void {
    $problems = ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), new TaskId('M1-T4'), true, []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith('PROGRESS.md has no entry for M1-T4 under "## Til review af Sylvester" that says GUARDRAILS 7.3');
});

it('does not take an entry of another section, or of the introduction, for the task\'s entry', function (): void {
    $markdown = "## Til review af Sylvester\n\nM1-T6 (GUARDRAILS 7.3) in the introduction.\n\n## Tolkninger\n\n- M1-T6: GUARDRAILS 7.3 in another section.\n";

    expect(ProgressAudit::problems(ProgressLedger::fromMarkdown($markdown), new TaskId('M1-T6'), true, []))->toHaveCount(2);
});

it('reports every commit that changes no file', function (): void {
    $problems = ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), new TaskId('M1-T3'), true, [
        new EmptyCommit(str_repeat('a', 40), 'M1-T3: fix acceptance item 6: handed to the integration step'),
    ]);

    expect($problems)->toBe([
        'The commit aaaaaaaaaaaa "M1-T3: fix acceptance item 6: handed to the integration step" changes no file. Record what it was meant to hand on in PROGRESS.md in a commit that changes it, and drop the empty commit.',
    ]);
});

it('finds the commits of a range that change no file, oldest first', function (): void {
    $repository = ScratchRepository::make();
    $repository->write('README.md', "# Scratch\n")->commit('initial');
    $repository->git('switch', '--quiet', '--create', 'wip/M1-T3');
    $first = $repository->commit('M1-T3: handed to the integration step');
    $repository->write('src/Foo.php', "<?php\n")->commit('M1-T3: the change');
    $second = $repository->commit('M1-T3: fix nothing');

    expect(GitEmptyCommits::in($repository->root, 'main..HEAD'))->toEqual([
        new EmptyCommit($first, 'M1-T3: handed to the integration step'),
        new EmptyCommit($second, 'M1-T3: fix nothing'),
    ])
        ->and(GitEmptyCommits::in($repository->root, 'main..HEAD~2'))->toEqual([new EmptyCommit($first, 'M1-T3: handed to the integration step')])
        ->and(GitEmptyCommits::in($repository->root, 'main..main'))->toBe([]);
});

it('counts a commit that only deletes a file as a change', function (): void {
    $repository = ScratchRepository::make();
    $repository->write('README.md', "# Scratch\n")->write('old.txt', "old\n")->commit('initial');
    $repository->git('switch', '--quiet', '--create', 'wip/M1-T3');
    $repository->delete('old.txt');
    $repository->commit('M1-T3: remove old.txt');

    expect(GitEmptyCommits::in($repository->root, 'main..HEAD'))->toBe([]);
});

it('refuses a range git cannot read, and one that would be an option', function (string $range, string $message): void {
    $repository = ScratchRepository::make();
    $repository->write('README.md', "# Scratch\n")->commit('initial');

    expect(static fn (): array => GitEmptyCommits::in($repository->root, $range))->toThrow(UnexpectedValueException::class, $message);
})->with([
    ['main..no-such-branch', 'git log cannot read the range main..no-such-branch'],
    ['--all', 'A revision range such as main..HEAD, not [--all].'],
    ['', 'A revision range such as main..HEAD, not [].'],
]);

it('parses the task, --changed-checks and --range, and refuses anything else', function (): void {
    $options = ProgressCheckOptions::parse(['--changed-checks', 'M0-T43', '--range=main..HEAD']);

    expect($options->task->value)->toBe('M0-T43')
        ->and($options->changedChecks)->toBeTrue()
        ->and($options->range)->toBe('main..HEAD')
        ->and(ProgressCheckOptions::parse(['M0-T43'])->range)->toBeNull()
        ->and(static fn (): ProgressCheckOptions => ProgressCheckOptions::parse([]))->toThrow(InvalidArgumentException::class, 'Name the task')
        ->and(static fn (): ProgressCheckOptions => ProgressCheckOptions::parse(['M0-T43', 'M0-T44']))->toThrow(InvalidArgumentException::class, '[M0-T44]')
        ->and(static fn (): ProgressCheckOptions => ProgressCheckOptions::parse(['M0-T43', '--range=']))->toThrow(InvalidArgumentException::class, '[--range=]')
        ->and(static fn (): ProgressCheckOptions => ProgressCheckOptions::parse(['M0-T43', '--dry-run']))->toThrow(InvalidArgumentException::class, '[--dry-run]');
});

it('runs as composer progress:check and exits 0, 1 or 2', function (): void {
    expect(ComposerScripts::steps('progress:check'))->toBe(['@php tools/bin/progress-check.php'])
        ->and(ComposerScripts::description('progress:check'))->toContain('GUARDRAILS 7.3');

    [$recorded, $recordedOutput] = runProgressCheck('M0-T43', '--changed-checks', '--range=HEAD..HEAD');
    [$missing, $missingOutput] = runProgressCheck('M9-T999');
    [$usage, $usageOutput] = runProgressCheck();

    expect([$recorded, $recordedOutput])->toBe([0, "PROGRESS.md records M0-T43 and its changed checks, and no commit of HEAD..HEAD is empty.\n"])
        ->and($missing)->toBe(1)
        ->and($missingOutput)->toContain('PROGRESS.md has no entry for M9-T999 under "## Kontroller kørt".')
        ->and($usage)->toBe(2)
        ->and($usageOutput)->toContain(ProgressCheckOptions::USAGE);
});

it('records the gate runs and the changed checks of every task the merge queue merged in M0', function (string $task, bool $changedChecks): void {
    $ledger = ProgressLedger::fromMarkdown((string) file_get_contents(Phpstan::root().'/PROGRESS.md'));

    expect(ProgressAudit::problems($ledger, new TaskId($task), $changedChecks, []))->toBe([]);
})->with([
    ['M0-T40', true],
    ['M0-T41a', true],
    ['M0-T41b', true],
    ['M0-T43', true],
    ['M0-T70', true],
    ['M0-T74', true],
    ['M0-T75', true],
    ['M0-T76', true],
    ['M0-T77', false],
    ['M0-T78', true],
]);

it('records what M0-T77 handed to the integration step: the MILESTONES 1.4 edit for review and the reading of the cms_ system columns', function (): void {
    $ledger = ProgressLedger::fromMarkdown((string) file_get_contents(Phpstan::root().'/PROGRESS.md'));
    $task = new TaskId('M0-T77');
    $review = array_filter($ledger->entries(ProgressLedger::REVIEW), static fn (string $entry): bool => $task->namedIn($entry) && str_contains($entry, 'MILESTONES') && str_contains($entry, '1.4'));
    $readings = array_filter($ledger->entries('Tolkninger'), static fn (string $entry): bool => $task->namedIn($entry) && str_contains($entry, '`cms_owner_actor`'));

    expect($review)->not->toBe([])
        ->and($readings)->not->toBe([]);
});

it('has the merge queue run composer progress:check before every fast-forward of main', function (): void {
    $workflow = (string) file_get_contents(Phpstan::root().'/.claude/workflows/cms-milestone.js');
    $fastForward = 'git -C ${REPO} merge --ff-only';
    $offset = 0;
    $merges = 0;

    while (($merge = strpos($workflow, $fastForward, $offset)) !== false) {
        $start = (int) strrpos(substr($workflow, 0, $merge), 'agent(');
        $prompt = substr($workflow, $start, $merge - $start);

        expect($prompt)->toContain('composer progress:check -- ${BLOCK}-')
            ->and($prompt)->toContain('--changed-checks')
            ->and($prompt)->toContain('--range=main..HEAD');
        $offset = $merge + 1;
        $merges++;
    }

    expect($merges)->toBeGreaterThan(0);
});
