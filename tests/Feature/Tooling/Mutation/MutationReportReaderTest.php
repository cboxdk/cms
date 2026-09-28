<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationCount;
use Cbox\Cms\Tooling\Mutation\Domain\MutationLedger;
use Cbox\Cms\Tooling\Mutation\Domain\MutationOutcome;
use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use InvalidArgumentException;

/*
 * The report line the Pest plugin PestMutationReport prints after the mutations: each changed
 * class is listed with its score, the step fails below 80 over all its mutations and names the
 * classes below it, and a missing report fails the step, because then nothing was checked. The
 * fast suites' reader records every mutation's outcome, and the reader of the step with Postgres
 * counts a mutation as caught when either run caught it.
 */

/**
 * The report of files whose first `caught` mutations were caught, with the ids `m1` to `m<n>`
 * after the file's name, so two runs of the same file give the same ids.
 *
 * @param  list<array{string, int, int}>  $files  path, mutations, caught
 */
function mutationReportLine(array $files): string
{
    return MutationReportReader::MARKER.json_encode([
        'files' => array_map(static fn (array $file): array => [
            'mutations' => array_map(
                static fn (int $number): array => ['caught' => $number <= $file[2], 'id' => basename($file[0], '.php').'-m'.$number],
                $file[1] === 0 ? [] : range(1, $file[1]),
            ),
            'path' => $file[0],
        ], $files),
        'format' => 2,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * A report of one file with the given mutations, by id, caught or not.
 *
 * @param  array<string, bool>  $mutations
 */
function mutationReportOf(string $path, array $mutations): string
{
    return MutationReportReader::MARKER.json_encode([
        'files' => [['mutations' => array_map(static fn (string $id, bool $caught): array => ['caught' => $caught, 'id' => $id], array_keys($mutations), $mutations), 'path' => $path]],
        'format' => 2,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function reportReader(): MutationReportReader
{
    return new MutationReportReader([
        new ChangedSource('packages/contracts/src/Ids/CommandName.php', CommandName::class),
        new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class),
        new ChangedSource('packages/contracts/src/Ids/Marker.php', 'Cbox\Cms\Contracts\Ids\Marker'),
    ], 80);
}

it('lists each changed class with its score and passes at 80 or more over all mutations, also with a class below 80', function (): void {
    $output = "  ..x..\n".mutationReportLine([
        ['packages/contracts/src/Ids/CommandName.php', 10, 10],
        ['packages/contracts/src/Ids/PrincipalId.php', 10, 7],
    ])."\n  Score: 85.00%\n";

    $reading = reportReader()->read(new ProcessOutcome(0, $output, 3.0));

    expect($reading->failure)->toBeNull()
        ->and($reading->notes)->toBe([
            'Cbox\Cms\Contracts\Ids\CommandName: 100.00%, 10 of 10 mutations caught',
            'Cbox\Cms\Contracts\Ids\PrincipalId: 70.00%, 7 of 10 mutations caught',
            'Cbox\Cms\Contracts\Ids\Marker: no mutations',
            'score 85.00% of 20 mutations, minimum 80%',
        ]);
});

it('fails below 80 over all mutations, whatever the exit code, and names the classes below 80', function (int $exitCode): void {
    $output = mutationReportLine([
        ['packages/contracts/src/Ids/CommandName.php', 4, 4],
        ['packages/contracts/src/Ids/PrincipalId.php', 6, 3],
    ]);

    $reading = reportReader()->read(new ProcessOutcome($exitCode, $output, 3.0));

    expect($reading->failure)->toBe('mutation score 70.00% is below 80%; below it: Cbox\Cms\Contracts\Ids\PrincipalId 50.00%')
        ->and($reading->notes)->toContain('Cbox\Cms\Contracts\Ids\PrincipalId: 50.00%, 3 of 6 mutations caught', 'score 70.00% of 10 mutations, minimum 80%');
})->with(['Pest\'s exit code 1' => 1, 'an exit code of 0 as well' => 0]);

it('passes classes without a mutation, such as an interface, and says so', function (): void {
    $reading = reportReader()->read(new ProcessOutcome(0, mutationReportLine([]), 1.0));

    expect($reading->failure)->toBeNull()
        ->and($reading->notes)->toBe([
            'Cbox\Cms\Contracts\Ids\CommandName: no mutations',
            'Cbox\Cms\Contracts\Ids\PrincipalId: no mutations',
            'Cbox\Cms\Contracts\Ids\Marker: no mutations',
            'no mutations in the changed sources',
        ]);
});

it('reads the last report line, and ignores files that are not the step\'s', function (): void {
    $output = mutationReportLine([['packages/contracts/src/Ids/CommandName.php', 10, 0]])."\n"
        .mutationReportLine([['packages/contracts/src/Ids/CommandName.php', 10, 9], ['packages/core/src/Other.php', 10, 0]]);

    $reading = reportReader()->read(new ProcessOutcome(0, $output, 1.0));

    expect($reading->failure)->toBeNull()
        ->and($reading->notes[0])->toBe('Cbox\Cms\Contracts\Ids\CommandName: 90.00%, 9 of 10 mutations caught')
        ->and(array_last($reading->notes))->toBe('score 90.00% of 10 mutations, minimum 80%');
});

it('fails when Pest printed no report it can read, because then no mutation was checked', function (string $output, int $exitCode, string $why): void {
    $reading = reportReader()->read(new ProcessOutcome($exitCode, $output, 1.0));

    expect($reading->failure)->toBe('Pest printed no mutation report, so no mutation was checked: '.$why)
        ->and($reading->notes)->toBe([]);
})->with([
    'a test failed first' => ["  FAILED  Tests\\Unit\\ATest\n", 1, 'a test failed before the mutations ran, or Pest stopped'],
    'no plugin' => ["  Score: 100.00%\n", 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'not JSON' => [MutationReportReader::MARKER."{\n", 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'another format' => [MutationReportReader::MARKER.'{"files": [], "format": 1}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'counts instead of mutations' => [MutationReportReader::MARKER.'{"files": [{"caught": 3, "mutations": 3, "path": "a.php"}], "format": 2}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'a caught state that is not a boolean' => [MutationReportReader::MARKER.'{"files": [{"mutations": [{"caught": 1, "id": "a"}], "path": "a.php"}], "format": 2}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'a mutation without an id' => [MutationReportReader::MARKER.'{"files": [{"mutations": [{"caught": true, "id": ""}], "path": "a.php"}], "format": 2}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'a mutation listed twice' => [MutationReportReader::MARKER.'{"files": [{"mutations": [{"caught": true, "id": "a"}, {"caught": false, "id": "a"}], "path": "a.php"}], "format": 2}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'a file listed twice' => [MutationReportReader::MARKER.'{"files": [{"mutations": [], "path": "a.php"}, {"mutations": [], "path": "a.php"}], "format": 2}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
]);

it('records every file of the report in the ledger, those it does not judge as well, and only a report it could read', function (): void {
    $ledger = new MutationLedger;
    $reader = new MutationReportReader([new ChangedSource('packages/contracts/src/Ids/CommandName.php', CommandName::class)], 80, records: $ledger);

    $reader->read(new ProcessOutcome(1, "  FAILED  Tests\\Unit\\ATest\n", 1.0));
    $unread = $ledger->recorded();

    $reading = $reader->read(new ProcessOutcome(0, mutationReportLine([
        ['packages/contracts/src/Ids/CommandName.php', 2, 2],
        ['packages/core/src/Doctor/Adapter/Probe.php', 3, 1],
    ]), 1.0));

    expect($unread)->toBeFalse()
        ->and($ledger->recorded())->toBeTrue()
        ->and($ledger->outcomes('packages/core/src/Doctor/Adapter/Probe.php'))->toBe(['Probe-m1' => true, 'Probe-m2' => false, 'Probe-m3' => false])
        ->and($ledger->outcomes('packages/contracts/src/Ids/CommandName.php'))->toBe(['CommandName-m1' => true, 'CommandName-m2' => true])
        ->and($ledger->outcomes('packages/core/src/Other.php'))->toBe([])
        ->and($reading->failure)->toBeNull()
        ->and($reading->notes)->toBe([
            'Cbox\Cms\Contracts\Ids\CommandName: 100.00%, 2 of 2 mutations caught',
            'score 100.00% of 2 mutations, minimum 80%',
        ]);
});

it('records the report for the step with Postgres without judging, when every changed source is in Adapter or Infrastructure', function (): void {
    $ledger = new MutationLedger;

    $reading = new MutationReportReader([], 80, records: $ledger)->read(new ProcessOutcome(0, mutationReportLine([['packages/core/src/Doctor/Adapter/Probe.php', 3, 0]]), 1.0));

    expect($reading->failure)->toBeNull()
        ->and($reading->notes)->toBe(['recorded for the step with Postgres: every changed source is in Adapter or Infrastructure'])
        ->and($ledger->outcomes('packages/core/src/Doctor/Adapter/Probe.php'))->toBe(['Probe-m1' => false, 'Probe-m2' => false, 'Probe-m3' => false]);
});

it('counts a mutation as caught when the fast suites\' run or its own caught it, as one run of both would', function (): void {
    $probe = new ChangedSource('packages/core/src/Doctor/Adapter/Probe.php', 'Cbox\Cms\Core\Doctor\Adapter\Probe');
    $ledger = new MutationLedger;
    $ledger->record(['packages/core/src/Doctor/Adapter/Probe.php' => [
        new MutationOutcome('a', true),
        new MutationOutcome('b', true),
        new MutationOutcome('c', false),
        new MutationOutcome('d', false),
        new MutationOutcome('e', false),
    ]]);
    $reader = new MutationReportReader([$probe], 80, counts: $ledger);

    $caught = $reader->read(new ProcessOutcome(0, mutationReportOf($probe->path, ['a' => false, 'b' => true, 'c' => true, 'd' => true, 'e' => false]), 1.0));
    $below = $reader->read(new ProcessOutcome(0, mutationReportOf($probe->path, ['a' => false, 'b' => false, 'c' => true, 'd' => false, 'e' => false]), 1.0));

    expect($caught->failure)->toBeNull()
        ->and($caught->notes)->toBe([
            'Cbox\Cms\Core\Doctor\Adapter\Probe: 80.00%, 4 of 5 mutations caught',
            'counted with the fast suites\' run, which caught 2 of them',
            'score 80.00% of 5 mutations, minimum 80%',
        ])
        ->and($below->failure)->toBe('mutation score 60.00% is below 80%; below it: Cbox\Cms\Core\Doctor\Adapter\Probe 60.00%');
});

it('counts only its own run when the fast suites\' run recorded no report, and a mutation only one run made with that run', function (): void {
    $probe = new ChangedSource('packages/core/src/Doctor/Adapter/Probe.php', 'Cbox\Cms\Core\Doctor\Adapter\Probe');
    $unrecorded = new MutationReportReader([$probe], 80, counts: new MutationLedger);
    $ledger = new MutationLedger;
    $ledger->record(['packages/core/src/Doctor/Adapter/Probe.php' => [new MutationOutcome('a', true), new MutationOutcome('z', false)]]);

    $alone = $unrecorded->read(new ProcessOutcome(0, mutationReportOf($probe->path, ['a' => false, 'b' => true]), 1.0));
    $both = new MutationReportReader([$probe], 80, counts: $ledger)->read(new ProcessOutcome(0, mutationReportOf($probe->path, ['a' => false, 'b' => true]), 1.0));

    expect($alone->failure)->toBe('mutation score 50.00% is below 80%; below it: Cbox\Cms\Core\Doctor\Adapter\Probe 50.00%')
        ->and($alone->notes)->toContain('the fast suites\' run recorded no report, so only this run counts')
        ->and($both->notes[0])->toBe('Cbox\Cms\Core\Doctor\Adapter\Probe: 66.67%, 2 of 3 mutations caught')
        ->and($both->failure)->toBe('mutation score 66.67% is below 80%; below it: Cbox\Cms\Core\Doctor\Adapter\Probe 66.67%');
});

it('refuses a ledger that lists a mutation twice', function (): void {
    expect(static fn () => new MutationLedger()->record(['a.php' => [new MutationOutcome('a', true), new MutationOutcome('a', false)]]))
        ->toThrow(InvalidArgumentException::class, 'The mutation a of a.php is recorded twice.');
});

it('refuses a reader without sources, a minimum that is not a percentage, and counts that are not counts', function (callable $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'no sources' => [static fn (): MutationReportReader => new MutationReportReader([], 80)],
    'no sources, counting another run' => [static fn (): MutationReportReader => new MutationReportReader([], 80, counts: new MutationLedger)],
    'a mutation without an id' => [static fn (): MutationOutcome => new MutationOutcome('', true)],
    'a minimum of 101' => [static fn (): MutationReportReader => new MutationReportReader([new ChangedSource('packages/a/src/A.php', 'A')], 101)],
    'more caught than made' => [static fn (): MutationCount => new MutationCount(1, 2)],
    'a negative count' => [static fn (): MutationCount => new MutationCount(-1, 0)],
]);
