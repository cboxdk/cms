<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Progress;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Cbox\Cms\Tooling\Progress\Boundary\GitEmptyCommits;
use Cbox\Cms\Tooling\Progress\Boundary\GitReviewCommits;
use Cbox\Cms\Tooling\Progress\Boundary\ProgressCheckOptions;
use Cbox\Cms\Tooling\Progress\Domain\CheckPaths;
use Cbox\Cms\Tooling\Progress\Domain\ChecksLog;
use Cbox\Cms\Tooling\Progress\Domain\EmptyCommit;
use Cbox\Cms\Tooling\Progress\Domain\ProgressAudit;
use Cbox\Cms\Tooling\Progress\Domain\ProgressLedger;
use Cbox\Cms\Tooling\Progress\Domain\ReviewCommit;
use Cbox\Cms\Tooling\Progress\Domain\ReviewCommitAudit;
use Cbox\Cms\Tooling\Progress\Domain\TaskId;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * `composer progress:check` (tools/bin/progress-check.php): whether PROGRESS.md and CHECKS-LOG.md
 * record a task before the merge queue of .claude/workflows/cms-milestone.js moves main. The tasks
 * merged through the queue from M0-T40 to M0-T78 changed checks that PROGRESS.md never recorded,
 * and M0-T77 landed empty commits that said their PROGRESS.md entries were handed to the
 * integration step, which never wrote them. The script's parts are tested here on scratch text and
 * a scratch repository, the script on this checkout, and the merge queue's prompts on the workflow
 * file.
 *
 * A review fix committed straight on main, such as `M0-review: ...`, never passes the merge queue
 * and so never runs the script, and d4cabbb, 53b6b14 and 1246f21 among others changed test
 * expectations without a GUARDRAILS 7.3 entry. ReviewCommitAudit holds every such commit in this
 * checkout's history to the same entries, read with GitReviewCommits.
 *
 * Since M0-D10 the records of changed checks live in CHECKS-LOG.md, under one heading per block
 * (GUARDRAILS 7.3, version 1.9), and "Til review af Sylvester" in PROGRESS.md holds only open
 * decisions; an entry there no longer counts as a record. A review commit made before the log
 * existed is still held to the entries it added to PROGRESS.md.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

const PROGRESS_SAMPLE = <<<'MD'
    # Fremdrift

    ## Tolkninger

    - M1-T3: a reading.

    ## Til review af Sylvester

    Open decisions:

    - M1-T4: a question about the naming.
    - M1-T5: Ændret test (GUARDRAILS 7.3): `BarTest`, recorded in the wrong file.

    ## Kontroller kørt

    - 2026-10-01, M1-T3: `composer check`: gates 1 to 6 pass.
    - 2026-10-01, M1-T4, after commit: `composer check:selftest` exit 0.
    - 2026-10-01, M1-T5: `composer check` exit 0.
    MD;

const CHECKS_LOG_SAMPLE = <<<'MD'
    # Ændrede kontroller

    Records under GUARDRAILS 7.3, one heading per block.

    ## M0

    - M1-T6: Ændret (GUARDRAILS 7.3): `BazTest`, under another block's heading.

    ## M1

    - M1-T3: Ændrede testforventninger (GUARDRAILS 7.3): `FooTest` expects bar,
      because the format changed.
    - M1-T4: a note on `QuxTest` that is no record.
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
    $log = ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE);

    expect($ledger->hasSection(ProgressLedger::REVIEW))->toBeTrue()
        ->and($ledger->hasSection('Blokeret'))->toBeFalse()
        ->and($ledger->entries(ProgressLedger::REVIEW))->toBe([
            'M1-T4: a question about the naming.',
            'M1-T5: Ændret test (GUARDRAILS 7.3): `BarTest`, recorded in the wrong file.',
        ])
        ->and($ledger->entries(ProgressLedger::CHECKS_RUN))->toHaveCount(3)
        ->and($ledger->entries('Blokeret'))->toBe([])
        ->and($log->entries('M1'))->toBe([
            'M1-T3: Ændrede testforventninger (GUARDRAILS 7.3): `FooTest` expects bar, because the format changed.',
            'M1-T4: a note on `QuxTest` that is no record.',
        ])
        ->and($log->entries('M0'))->toHaveCount(1)
        ->and($log->entries('M2'))->toBe([]);
});

it('names the block of a task and of a review label', function (string $task, string $block): void {
    expect(new TaskId($task)->block())->toBe($block);
})->with([
    ['M0-T43', 'M0'],
    ['M0-review', 'M0'],
    ['M12-R1-2', 'M12'],
    ['B3-T1', 'B3'],
]);

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

it('finds nothing to fault when the task has its gate runs and its GUARDRAILS 7.3 entry in CHECKS-LOG.md', function (): void {
    $ledger = ProgressLedger::fromMarkdown(PROGRESS_SAMPLE);
    $log = ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE);

    expect(ProgressAudit::problems($ledger, $log, new TaskId('M1-T3'), true, []))->toBe([])
        ->and(ProgressAudit::problems($ledger, $log, new TaskId('M1-T4'), false, []))->toBe([])
        ->and($log->records(new TaskId('M1-T3')))->toBeTrue();
});

it('requires an entry under Kontroller kørt for every task', function (): void {
    $problems = ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE), new TaskId('M1-T7'), false, []);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith('PROGRESS.md has no entry for M1-T7 under "## Kontroller kørt".');
});

it('requires a GUARDRAILS 7.3 entry in CHECKS-LOG.md when the task changed checks, and an entry there without the rule will not do', function (): void {
    $problems = ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE), new TaskId('M1-T4'), true, []);

    expect($problems)->toBe([
        'CHECKS-LOG.md has no entry for M1-T4 under "## M1" that says GUARDRAILS 7.3, although the task changed or removed checks. Add one that names each changed or removed test expectation, suite, tool configuration or CI file and why; "## Til review af Sylvester" in PROGRESS.md holds only open decisions.',
    ]);
});

it('does not take a GUARDRAILS 7.3 entry under Til review af Sylvester in PROGRESS.md for the record of a changed check', function (): void {
    $ledger = ProgressLedger::fromMarkdown(PROGRESS_SAMPLE);
    $task = new TaskId('M1-T5');

    expect(array_any($ledger->entries(ProgressLedger::REVIEW), static fn (string $entry): bool => $task->namedIn($entry) && ChecksLog::isRecord($entry)))->toBeTrue()
        ->and(ProgressAudit::problems($ledger, ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE), $task, true, []))->toHaveCount(1)
        ->and(ProgressAudit::problems($ledger, ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE), $task, true, [])[0])->toStartWith('CHECKS-LOG.md has no entry for M1-T5 under "## M1" that says GUARDRAILS 7.3');
});

it('does not take an entry under another block\'s heading, or in the introduction, for the task\'s record', function (): void {
    $markdown = "# Ændrede kontroller\n\nM1-T6 (GUARDRAILS 7.3) in the introduction.\n\n## M1\n\n- M1-T3: GUARDRAILS 7.3 for another task.\n";

    expect(ChecksLog::fromMarkdown($markdown)->records(new TaskId('M1-T6')))->toBeFalse()
        ->and(ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE)->records(new TaskId('M1-T6')))->toBeFalse()
        ->and(ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE)->records(new TaskId('M0-T6')))->toBeFalse()
        ->and(ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), ChecksLog::fromMarkdown($markdown), new TaskId('M1-T6'), true, []))->toHaveCount(2);
});

it('reports every commit that changes no file', function (): void {
    $problems = ProgressAudit::problems(ProgressLedger::fromMarkdown(PROGRESS_SAMPLE), ChecksLog::fromMarkdown(CHECKS_LOG_SAMPLE), new TaskId('M1-T3'), true, [
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

    [$unrecorded, $unrecordedOutput] = runProgressCheck('M9-T999', '--changed-checks');
    [$plain, $plainOutput] = runProgressCheck('M0-T77');

    expect([$recorded, $recordedOutput])->toBe([0, "PROGRESS.md records M0-T43, CHECKS-LOG.md its changed checks, and no commit of HEAD..HEAD is empty.\n"])
        ->and([$plain, $plainOutput])->toBe([0, "PROGRESS.md records M0-T77.\n"])
        ->and($missing)->toBe(1)
        ->and($missingOutput)->toContain('PROGRESS.md has no entry for M9-T999 under "## Kontroller kørt".')
        ->and($missingOutput)->not->toContain('CHECKS-LOG.md')
        ->and($unrecorded)->toBe(1)
        ->and($unrecordedOutput)->toContain('PROGRESS.md has no entry for M9-T999 under "## Kontroller kørt".')
        ->and($unrecordedOutput)->toContain('CHECKS-LOG.md has no entry for M9-T999 under "## M9" that says GUARDRAILS 7.3')
        ->and($usage)->toBe(2)
        ->and($usageOutput)->toContain(ProgressCheckOptions::USAGE);
});

it('records the gate runs and the changed checks of every task the merge queue merged in M0', function (string $task, bool $changedChecks): void {
    $ledger = ProgressLedger::fromMarkdown((string) file_get_contents(Phpstan::root().'/PROGRESS.md'));
    $log = ChecksLog::fromMarkdown((string) file_get_contents(Phpstan::root().'/'.ChecksLog::FILE));

    expect(ProgressAudit::problems($ledger, $log, new TaskId($task), $changedChecks, []))->toBe([]);
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
    $log = ChecksLog::fromMarkdown((string) file_get_contents(Phpstan::root().'/'.ChecksLog::FILE));
    $task = new TaskId('M0-T77');
    $review = array_filter($log->entries('M0'), static fn (string $entry): bool => $task->namedIn($entry) && str_contains($entry, 'MILESTONES') && str_contains($entry, '1.4'));
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
            ->and($prompt)->toContain('--range=main..HEAD')
            ->and($prompt)->toContain('in CHECKS-LOG.md, under the heading "## ${BLOCK}"')
            ->and($prompt)->not->toMatch('/under "Til review af Sylvester", one entry/');
        $offset = $merge + 1;
        $merges++;
    }

    expect($merges)->toBeGreaterThan(0);
});

it('tells every agent that records changed checks to write them in CHECKS-LOG.md, and leaves Til review af Sylvester to open decisions', function (string $file): void {
    $text = (string) file_get_contents(Phpstan::root().'/'.$file);

    expect($text)->toContain('CHECKS-LOG.md')
        ->and($text)->not->toContain('under "Til review af Sylvester" that says GUARDRAILS 7.3')
        ->and($text)->not->toContain('under "Til review af Sylvester", one entry')
        ->and($text)->not->toContain('"Til review af Sylvester" with GUARDRAILS 7.3');
})->with(['CLAUDE.md', 'AGENTS.md', '.claude/workflows/cms-milestone.js']);

it('keeps CHECKS-LOG.md at the root with a heading per block', function (): void {
    $markdown = (string) file_get_contents(Phpstan::root().'/'.ChecksLog::FILE);

    expect($markdown)->toStartWith("# Ændrede kontroller\n")
        ->and($markdown)->toContain("\n## M0\n")
        ->and(ChecksLog::fromMarkdown($markdown)->entries('M0'))->not->toBe([]);
});

it('tells a review commit by the first line of its message, and names its label and its hash', function (): void {
    $commit = new ReviewCommit('d4cabbb0123456789abcdef0123456789abcdef0', 'M0-review: a lock timeout aborted maintenance', [], [], []);
    $later = new ReviewCommit('d4cabbb0123456789abcdef0123456789abcdef0', 'M12-review: fix regression in the queue', [], [], []);

    expect(ReviewCommit::isReviewSubject('M0-review: a fix'))->toBeTrue()
        ->and(ReviewCommit::isReviewSubject('M12-review: fix regression in the queue'))->toBeTrue()
        ->and(ReviewCommit::isReviewSubject('M0-T43: marker gate'))->toBeFalse()
        ->and(ReviewCommit::isReviewSubject('M0-review a fix'))->toBeFalse()
        ->and(ReviewCommit::isReviewSubject('Revert "M0-review: a fix"'))->toBeFalse()
        ->and($commit->label()->value)->toBe('M0-review')
        ->and($later->label()->value)->toBe('M12-review')
        ->and(ReviewCommit::labelOf('M12-review: fix regression in the queue')->block())->toBe('M12')
        ->and($commit->namedBy('M0-review d4cabbb: the tests'))->toBeTrue()
        ->and($commit->namedBy('commit d4cabbb0123 changed'))->toBeTrue()
        ->and($commit->namedBy('d4cabb is too short'))->toBeFalse()
        ->and($commit->namedBy('d4cabbc is another commit'))->toBeFalse()
        ->and($commit->namedBy('ad4cabbb is not it'))->toBeFalse();
});

it('counts the tests, the testkit, the tooling, the analysis configuration and the CI files as checks', function (string $path, bool $check): void {
    expect(CheckPaths::isCheck($path))->toBe($check);
})->with([
    ['packages/core/tests/Postgres/PartitionManagerTest.php', true],
    ['packages/generators/tests/Schema/Fakes/ColourFieldType.php', true],
    ['tests/Support/Arch/Egress.php', true],
    ['tests/Feature/Tooling/Progress/ProgressCheckTest.php', true],
    ['packages/testkit/src/ReceiptStore/ReceiptStoreContract.php', true],
    ['tools/src/Progress/Domain/ProgressAudit.php', true],
    ['js/tooling/eslint.config.js', true],
    ['.github/workflows/ci.yml', true],
    ['phpunit.xml', true],
    ['phpstan.neon', true],
    ['rector.php', true],
    ['pint.json', true],
    ['bin/ci', true],
    ['compose.ci.yaml', true],
    ['packages/core/src/Partitions/Infrastructure/Run.php', false],
    ['packages/contracts/resources/schemas/blueprint.v1.json', false],
    ['PROGRESS.md', false],
    ['compose.yaml', false],
    ['tests', false],
    ['packages/core/src/Tests.php', false],
]);

it('finds the entries of a section that a ledger added since an earlier one, an edited entry among them', function (): void {
    $before = ProgressLedger::fromMarkdown("## Kontroller kørt\n\n- one\n- two\n- two\n");
    $after = ProgressLedger::fromMarkdown("## Kontroller kørt\n\n- one\n- two\n- two\n- two\n- three,\n  continued\n\n## Til review af Sylvester\n\n- new\n");

    expect($after->addedSince($before, ProgressLedger::CHECKS_RUN))->toBe(['two', 'three, continued'])
        ->and($after->addedSince($before, ProgressLedger::REVIEW))->toBe(['new'])
        ->and($before->addedSince($after, ProgressLedger::CHECKS_RUN))->toBe([]);
});

it('holds a review commit to its gate runs, and to a GUARDRAILS 7.3 entry when it changed or removed checks, or to later entries that name its hash', function (): void {
    $hash = 'd4cabbb0123456789abcdef0123456789abcdef0';
    $changed = ['packages/core/tests/Actions/MaintainPartitionsTest.php'];
    $recorded = new ReviewCommit($hash, 'M0-review: a fix', $changed, ['M0-review: Ændrede testforventninger (GUARDRAILS 7.3): `MaintainPartitionsTest`.'], ['2026-09-27, M0-review (a fix): composer check exit 0.']);
    $newChecksOnly = new ReviewCommit($hash, 'M0-review: a fix', [], [], ['2026-09-27, M0-review (a fix): composer check exit 0.']);
    $bare = new ReviewCommit($hash, 'M0-review: a fix', $changed, ['M0-review: a question without the rule.'], ['2026-09-27, M0-T43: composer check exit 0.']);
    $empty = ProgressLedger::fromMarkdown('');
    $noLog = ChecksLog::fromMarkdown('');
    $later = ProgressLedger::fromMarkdown("## Kontroller kørt\n\n- 2026-09-28, M0-review d4cabbb: not recorded when it was committed.\n");
    $laterLog = ChecksLog::fromMarkdown("# Ændrede kontroller\n\n## M0\n\n- M0-review d4cabbb: Ændrede testforventninger (GUARDRAILS 7.3): `MaintainPartitionsTest`.\n");

    expect(ReviewCommitAudit::problems($empty, $noLog, [$recorded, $newChecksOnly]))->toBe([])
        ->and(ReviewCommitAudit::problems($later, $laterLog, [$bare]))->toBe([])
        ->and(ReviewCommitAudit::problems($empty, $noLog, [$bare]))->toBe([
            'The review commit d4cabbb "M0-review: a fix" added no entry for M0-review under "## Kontroller kørt", and no entry there names d4cabbb. Add one that names d4cabbb, with the gates that ran on it and their results.',
            'The review commit d4cabbb "M0-review: a fix" changed or removed checks (packages/core/tests/Actions/MaintainPartitionsTest.php) but added no entry for M0-review to CHECKS-LOG.md under "## M0" that says GUARDRAILS 7.3, and no such entry there names d4cabbb. Add one that names d4cabbb and each changed or removed test expectation, suite, tool configuration or CI file, and why.',
        ]);
});

it('does not take a later entry that names the hash without GUARDRAILS 7.3, under another block, or in PROGRESS.md, for the review entry', function (): void {
    $bare = new ReviewCommit(str_repeat('ab', 20), 'M0-review: a fix', ['tests/Arch/MarkersTest.php'], [], []);
    $ledger = ProgressLedger::fromMarkdown("## Til review af Sylvester\n\n- M0-review abababa: GUARDRAILS 7.3 in PROGRESS.md.\n\n## Tolkninger\n\n- M0-review abababa: GUARDRAILS 7.3 in another section.\n\n## Kontroller kørt\n\n- M0-review abababa: composer check exit 0.\n");
    $log = ChecksLog::fromMarkdown("## M0\n\n- M0-review abababa: a note.\n\n## M1\n\n- M0-review abababa: GUARDRAILS 7.3 under another block.\n");

    expect(ReviewCommitAudit::problems($ledger, $log, [$bare]))->toHaveCount(1)
        ->and(ReviewCommitAudit::problems($ledger, $log, [$bare])[0])->toContain('changed or removed checks (tests/Arch/MarkersTest.php)');
});

it('reads the review commits of a history, oldest first, with the checks they changed or removed and the entries they added', function (): void {
    $progress = static fn (string $review, string $checks): string => "# Fremdrift\n\n## Til review af Sylvester\n\n{$review}\n## Kontroller kørt\n\n{$checks}";
    $repository = ScratchRepository::make();
    $repository->write('tests/FooTest.php', "<?php\n// one\n")
        ->write('tests/OldTest.php', "<?php\n")
        ->write('src/Foo.php', "<?php\n")
        ->commit('M0-T1: initial');
    $first = $repository->write('tests/FooTest.php', "<?php\n// two\n")
        ->write('src/Foo.php', "<?php\n// changed\n")
        ->write('PROGRESS.md', $progress("- M0-review: Ændret (GUARDRAILS 7.3): `FooTest`.\n", "- 2026-09-27, M0-review (foo): exit 0.\n"))
        ->commit('M0-review: foo expects two');
    $repository->write('src/Bar.php', "<?php\n")->commit('M0-T2: bar');
    $repository->git('mv', 'tests/OldTest.php', 'tests/NewTest.php');
    $second = $repository->write('tests/AddedTest.php', "<?php\n")
        ->write('tests/FooTest.php', "<?php\n// two\n// three\n")
        ->commit('M0-review: rename the old test');

    expect(GitReviewCommits::in($repository->root, 'HEAD'))->toEqual([
        new ReviewCommit($first, 'M0-review: foo expects two', ['tests/FooTest.php'], ['M0-review: Ændret (GUARDRAILS 7.3): `FooTest`.'], ['2026-09-27, M0-review (foo): exit 0.']),
        new ReviewCommit($second, 'M0-review: rename the old test', ['tests/OldTest.php'], [], []),
    ])
        ->and(GitReviewCommits::in($repository->root, 'HEAD~2'))->toHaveCount(1)
        ->and(GitReviewCommits::in($repository->root, 'HEAD~3'))->toBe([]);
});

it('reads the records of a review commit from CHECKS-LOG.md once the log exists, and no longer from Til review af Sylvester', function (): void {
    $progress = static fn (string $review, string $checks): string => "# Fremdrift\n\n## Til review af Sylvester\n\n{$review}\n## Kontroller kørt\n\n{$checks}";
    $log = static fn (string $m0, string $m1 = ''): string => "# Ændrede kontroller\n\n## M0\n\n{$m0}\n## M1\n\n{$m1}";
    $repository = ScratchRepository::make();
    $repository->write('tests/FooTest.php', "<?php\n// one\n")
        ->write('PROGRESS.md', $progress('', ''))
        ->commit('M0-T1: initial');
    $legacy = $repository->write('tests/FooTest.php', "<?php\n// two\n")
        ->write('PROGRESS.md', $progress("- M0-review: Ændret (GUARDRAILS 7.3): `FooTest`.\n", "- M0-review (foo): exit 0.\n"))
        ->commit('M0-review: foo expects two');
    $repository->write(ChecksLog::FILE, $log("- M0-review: Ændret (GUARDRAILS 7.3): `FooTest`.\n"))->commit('M0-D10: the log');
    $inProgress = $repository->write('tests/FooTest.php', "<?php\n// three\n")
        ->write('PROGRESS.md', $progress("- M0-review: Ændret (GUARDRAILS 7.3): `FooTest` in the wrong file.\n", "- M0-review (foo): exit 0.\n- M0-review (bar): exit 0.\n"))
        ->commit('M0-review: foo expects three');
    $inLog = $repository->write('tests/FooTest.php', "<?php\n// four\n")
        ->write(ChecksLog::FILE, $log("- M0-review: Ændret (GUARDRAILS 7.3): `FooTest`.\n- M0-review: Ændret (GUARDRAILS 7.3): `FooTest` expects four.\n", "- M0-review: under M1.\n"))
        ->commit('M0-review: foo expects four');

    $commits = GitReviewCommits::in($repository->root, 'HEAD');

    expect($commits)->toEqual([
        new ReviewCommit($legacy, 'M0-review: foo expects two', ['tests/FooTest.php'], ['M0-review: Ændret (GUARDRAILS 7.3): `FooTest`.'], ['M0-review (foo): exit 0.']),
        new ReviewCommit($inProgress, 'M0-review: foo expects three', ['tests/FooTest.php'], [], ['M0-review (bar): exit 0.']),
        new ReviewCommit($inLog, 'M0-review: foo expects four', ['tests/FooTest.php'], ['M0-review: Ændret (GUARDRAILS 7.3): `FooTest` expects four.'], []),
    ])
        ->and(ReviewCommitAudit::problems(ProgressLedger::fromMarkdown(''), ChecksLog::fromMarkdown(''), [$commits[1]]))->toHaveCount(1)
        ->and(ReviewCommitAudit::problems(ProgressLedger::fromMarkdown(''), ChecksLog::fromMarkdown(''), [$commits[1]])[0])->toContain('added no entry for M0-review to CHECKS-LOG.md under "## M0"');
});

it('refuses a revision git cannot read, and one that would be an option', function (string $revision, string $message): void {
    $repository = ScratchRepository::make();
    $repository->write('README.md', "# Scratch\n")->commit('initial');

    expect(static fn (): array => GitReviewCommits::in($repository->root, $revision))->toThrow(UnexpectedValueException::class, $message);
})->with([
    ['no-such-branch', 'git log cannot read no-such-branch'],
    ['--all', 'A revision such as HEAD, not [--all].'],
    ['', 'A revision such as HEAD, not [].'],
]);

it('records the gate runs and the changed checks of every review commit made straight on main', function (): void {
    $ledger = ProgressLedger::fromMarkdown((string) file_get_contents(Phpstan::root().'/PROGRESS.md'));
    $log = ChecksLog::fromMarkdown((string) file_get_contents(Phpstan::root().'/'.ChecksLog::FILE));

    expect(ReviewCommitAudit::problems($ledger, $log, GitReviewCommits::in(Phpstan::root(), 'HEAD')))->toBe([]);
});
