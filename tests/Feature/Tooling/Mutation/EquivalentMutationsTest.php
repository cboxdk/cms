<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Mutation\Adapter\PestMutationSites;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationShardReportJson;
use Cbox\Cms\Tooling\Mutation\Domain\CaughtByFastSuites;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\ClassTally;
use Cbox\Cms\Tooling\Mutation\Domain\EquivalentMutation;
use Cbox\Cms\Tooling\Mutation\Domain\EquivalentMutations;
use Cbox\Cms\Tooling\Mutation\Domain\MutationCount;
use Cbox\Cms\Tooling\Mutation\Domain\MutationLedger;
use Cbox\Cms\Tooling\Mutation\Domain\MutationOutcome;
use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShardReport;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;
use Closure;
use InvalidArgumentException;
use Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use Pest\Mutate\Mutators\Number\DecrementInteger;
use Pest\Mutate\Mutators\Number\IncrementInteger;

/*
 * The list of equivalent mutations (Sylvester's decision of 2 October): each entry names a source
 * below packages/<package>/src, the line and Pest's mutator, with its reason. A listed mutation is
 * left out of its class's score and never counted as caught; an entry that no longer names a
 * surviving mutation fails, both here against the sources as they are and in the step that
 * judges the source after a run of every suite.
 */

/**
 * Where Pest's mutators can mutate the repository's sources, as EquivalentMutations::unsited()
 * asks for them.
 *
 * @return Closure(string, string): ?list<int>
 */
function repositoryMutationSites(): Closure
{
    $root = dirname(__DIR__, 4);

    return static fn (string $path, string $mutator): ?array => is_file($root.'/'.$path)
        ? PestMutationSites::lines((string) file_get_contents($root.'/'.$path), $mutator)
        : null;
}

function pacingPath(): string
{
    return 'packages/core/src/Subscriptions/Adapter/SystemPacing.php';
}

function pacingEntry(int $line = 27, string $mutator = GreaterToGreaterOrEqual::class): EquivalentMutation
{
    return new EquivalentMutation(pacingPath(), $line, $mutator, 'usleep(0) returns at once.');
}

/**
 * A report line of the pacing source with the given mutations: id, caught, line, mutator.
 *
 * @param  list<array{string, bool, int, string}>  $mutations
 */
function pacingReport(array $mutations): string
{
    return MutationReportReader::MARKER.json_encode([
        'files' => [[
            'mutations' => array_map(static fn (array $mutation): array => ['caught' => $mutation[1], 'id' => $mutation[0], 'line' => $mutation[2], 'mutator' => $mutation[3]], $mutations),
            'path' => pacingPath(),
        ]],
        'format' => MutationReportReader::FORMAT,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function pacingSource(): ChangedSource
{
    return new ChangedSource(pacingPath(), SystemPacing::class);
}

it('names, for every entry, a mutation Pest makes of the source as it is, with a reason', function (): void {
    $kernel = EquivalentMutations::kernel();

    expect($kernel->entries)->not->toBe([])
        ->and($kernel->unsited(repositoryMutationSites()))->toBe([]);

    foreach ($kernel->entries as $entry) {
        expect($entry->path)->toMatch(EquivalentMutation::SOURCE_PATTERN)
            ->and(trim($entry->reason))->not->toBe('');
    }
});

it('fails on a planted entry that names no mutation of the source, and on one whose source is gone', function (): void {
    $planted = new EquivalentMutations([
        ...EquivalentMutations::kernel()->entries,
        pacingEntry(21),
        new EquivalentMutation('packages/core/src/Gone/Adapter/Gone.php', 1, TrueToFalse::class, 'Planted.'),
    ]);

    expect($planted->unsited(repositoryMutationSites()))->toBe([
        pacingPath().' line 21 GreaterToGreaterOrEqual names no mutation Pest makes of the source: the code moved or changed, so the entry goes',
        'packages/core/src/Gone/Adapter/Gone.php line 1 TrueToFalse names a source that does not exist',
    ]);
});

it('keeps the classes whose equivalent constructs were removed off the list', function (string $path): void {
    expect(EquivalentMutations::kernel()->of($path))->toBe([]);
})->with([
    'TypeRules' => 'packages/contracts/src/Validation/TypeRules.php',
    'HrtimeStopwatch' => 'packages/core/src/Pipeline/Adapter/HrtimeStopwatch.php',
    'RegistryAffectedProjections' => 'packages/core/src/Pipeline/Domain/RegistryAffectedProjections.php',
]);

it('finds the lines where a mutator can mutate a source', function (): void {
    $source = "<?php\n\nfunction wait(int \$ms): void\n{\n    if (\$ms > 0) {\n        usleep(\$ms * 1000);\n    }\n}\n";

    expect(PestMutationSites::lines($source, GreaterToGreaterOrEqual::class))->toBe([5])
        ->and(PestMutationSites::lines($source, DecrementInteger::class))->toBe([5, 6])
        ->and(PestMutationSites::lines($source, TrueToFalse::class))->toBe([])
        ->and(static fn (): array => PestMutationSites::lines($source, ChangedSource::class))->toThrow(InvalidArgumentException::class, 'is not a mutator of pest-plugin-mutate');
});

it('leaves a listed mutation out of the count, never as caught, and says how many', function (): void {
    $judgement = new EquivalentMutations([pacingEntry(), pacingEntry(28, DecrementInteger::class)])->judge(pacingPath(), [
        new MutationOutcome('a', true, 21, DecrementInteger::class),
        new MutationOutcome('b', false, 27, GreaterToGreaterOrEqual::class),
        new MutationOutcome('c', false, 28, DecrementInteger::class),
        new MutationOutcome('d', true, 28, IncrementInteger::class),
        new MutationOutcome('e', false, 27, IncrementInteger::class),
    ]);

    expect($judgement->stale)->toBe([])
        ->and($judgement->count)->toEqual(new MutationCount(3, 2, 2));
});

it('finds an entry stale when no mutation of the run matches it, or a test caught the one it names', function (): void {
    $judgement = new EquivalentMutations([pacingEntry(), pacingEntry(28, DecrementInteger::class)])->judge(pacingPath(), [
        new MutationOutcome('a', true, 27, GreaterToGreaterOrEqual::class),
        new MutationOutcome('b', false, 29, DecrementInteger::class),
    ]);

    expect($judgement->stale)->toBe([
        pacingPath().' line 27 GreaterToGreaterOrEqual is caught by a test, so it is not equivalent and the entry goes',
        pacingPath().' line 28 DecrementInteger names no mutation of the run: the code moved or changed, so the entry goes',
    ])
        ->and($judgement->count)->toEqual(new MutationCount(1, 0, 1));
});

it('scores a class without its listed mutations in the step, and shows them', function (): void {
    $tally = new MutationTally;
    $reader = new MutationReportReader([pacingSource()], 80, tally: $tally, equivalents: new EquivalentMutations([pacingEntry(), pacingEntry(28, DecrementInteger::class)]));

    $reading = $reader->read(new ProcessOutcome(0, pacingReport([
        ['a', true, 21, DecrementInteger::class],
        ['b', false, 27, GreaterToGreaterOrEqual::class],
        ['c', false, 28, DecrementInteger::class],
        ['d', true, 28, IncrementInteger::class],
    ]), 1.0));

    expect($reading->failure)->toBeNull()
        ->and($reading->notes[0])->toBe('Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing: 100.00%, 2 of 2 mutations caught, 2 equivalent left out')
        ->and($tally->classes())->toEqual([new ClassTally(pacingPath(), pacingSource()->name, new MutationCount(2, 2, 2))]);
});

it('fails the step on a planted entry that matches no surviving mutation, also when the class reaches 80', function (): void {
    $reader = new MutationReportReader([pacingSource()], 80, equivalents: new EquivalentMutations([pacingEntry(), pacingEntry(21, TrueToFalse::class)]));

    $reading = $reader->read(new ProcessOutcome(0, pacingReport([
        ['a', true, 21, DecrementInteger::class],
        ['b', false, 27, GreaterToGreaterOrEqual::class],
    ]), 1.0));

    expect($reading->failure)->toBe('stale equivalent mutations: '.pacingPath().' line 21 TrueToFalse names no mutation of the run: the code moved or changed, so the entry goes');
});

it('fails the step on an entry a test of either run caught, and counts it as caught nowhere', function (): void {
    $ledger = new MutationLedger;
    $ledger->record([pacingPath() => [new MutationOutcome('b', true, 27, GreaterToGreaterOrEqual::class), new MutationOutcome('a', false, 21, DecrementInteger::class)]]);
    $reader = new MutationReportReader([pacingSource()], 80, counts: $ledger, equivalents: new EquivalentMutations([pacingEntry()]));

    $reading = $reader->read(new ProcessOutcome(0, pacingReport([['a', true, 21, DecrementInteger::class]]), 1.0));

    expect($reading->failure)->toBe('stale equivalent mutations: '.pacingPath().' line 27 GreaterToGreaterOrEqual is caught by a test, so it is not equivalent and the entry goes')
        ->and($reading->notes[0])->toBe('Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing: 100.00%, 1 of 1 mutations caught, 1 equivalent left out');
});

it('lets the step with Postgres run for a source with an entry, so its reader judges the entry over every suite', function (): void {
    $ledger = new MutationLedger;
    $ledger->record([pacingPath() => [new MutationOutcome('a', true, 21, DecrementInteger::class)]]);

    expect(new CaughtByFastSuites([pacingSource()], $ledger)->passedWithout())->not->toBeNull()
        ->and(new CaughtByFastSuites([pacingSource()], $ledger, equivalents: new EquivalentMutations([pacingEntry()]))->passedWithout())->toBeNull();
});

it('carries the equivalent mutations of each class through a shard\'s report', function (): void {
    $report = new MutationShardReport(1, 1, [pacingPath()], true, [new ClassTally(pacingPath(), pacingSource()->name, new MutationCount(8, 8, 4))]);

    expect(MutationShardReportJson::decode(MutationShardReportJson::encode($report)))->toEqual($report)
        ->and($report->classes[0]->describe())->toBe('Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing: 100.00%, 8 of 8 mutations caught, 4 equivalent left out');
});

it('refuses an entry outside packages/<package>/src, without a mutator of Pest, without a reason, or listed twice', function (Closure $make, string $message): void {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a test file' => [static fn (): EquivalentMutation => new EquivalentMutation('tests/Feature/Tooling/Mutation/EquivalentMutationsTest.php', 1, TrueToFalse::class, 'A test.'), 'names a PHP file below packages/<package>/src'],
    'the tooling' => [static fn (): EquivalentMutation => new EquivalentMutation('tools/src/Mutation/Domain/EquivalentMutations.php', 1, TrueToFalse::class, 'Tooling.'), 'names a PHP file below packages/<package>/src'],
    'a package\'s tests' => [static fn (): EquivalentMutation => new EquivalentMutation('packages/core/tests/Pipeline/Probe/ProbeNoted.php', 1, TrueToFalse::class, 'A test.'), 'names a PHP file below packages/<package>/src'],
    'a path out of src' => [static fn (): EquivalentMutation => new EquivalentMutation('packages/core/src/../tests/A.php', 1, TrueToFalse::class, 'Out.'), 'names a PHP file below packages/<package>/src'],
    'line 0' => [static fn (): EquivalentMutation => pacingEntry(0), 'names a line from 1'],
    'a mutator by short name' => [static fn (): EquivalentMutation => pacingEntry(27, 'GreaterToGreaterOrEqual'), 'names a mutator of pest-plugin-mutate by class'],
    'no reason' => [static fn (): EquivalentMutation => new EquivalentMutation(pacingPath(), 27, GreaterToGreaterOrEqual::class, ' '), 'needs a one-line reason'],
    'listed twice' => [static fn (): EquivalentMutations => new EquivalentMutations([pacingEntry(), pacingEntry()]), 'is listed twice'],
]);
