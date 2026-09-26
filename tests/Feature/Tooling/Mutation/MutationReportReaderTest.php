<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationCount;
use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use InvalidArgumentException;

/*
 * The report line the Pest plugin PestMutationReport prints after the mutations: each changed
 * class is listed with its score, the step fails below 80 over all its mutations and names the
 * classes below it, and a missing report fails the step, because then nothing was checked.
 */

/**
 * @param  list<array{string, int, int}>  $files  path, mutations, caught
 */
function mutationReportLine(array $files): string
{
    return MutationReportReader::MARKER.json_encode([
        'files' => array_map(static fn (array $file): array => ['caught' => $file[2], 'mutations' => $file[1], 'path' => $file[0]], $files),
        'format' => 1,
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
    'another format' => [MutationReportReader::MARKER.'{"files": [], "format": 2}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'more caught than made' => [MutationReportReader::MARKER.'{"files": [{"caught": 3, "mutations": 2, "path": "a.php"}], "format": 1}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
    'a count that is not a number' => [MutationReportReader::MARKER.'{"files": [{"caught": "3", "mutations": 3, "path": "a.php"}], "format": 1}', 0, 'the plugin in composer.json\'s extra.pest.plugins did not run'],
]);

it('refuses a reader without sources, a minimum that is not a percentage, and counts that are not counts', function (callable $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'no sources' => [static fn (): MutationReportReader => new MutationReportReader([], 80)],
    'a minimum of 101' => [static fn (): MutationReportReader => new MutationReportReader([new ChangedSource('packages/a/src/A.php', 'A')], 101)],
    'more caught than made' => [static fn (): MutationCount => new MutationCount(1, 2)],
    'a negative count' => [static fn (): MutationCount => new MutationCount(-1, 0)],
]);
