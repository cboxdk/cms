<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Core\ReadModels\Actions\RebuildReadModels;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Core\Tests\Operations\Fakes\FakeOperationRunner;
use Cbox\Cms\Core\Tests\ReadModels\Fakes\FakeReadModelStore;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;

/*
 * cms:types:rebuild with the action on fakes (the store, the runner, the access contexts and the
 * identity) and the FakeClock: what it prints, what it logs and how it exits for each outcome.
 * RebuildReadModelsTest in the Postgres suite rebuilds real type tables with the action.
 */

/**
 * Binds the action to fakes with three entries of test:note, a service actor in the given class
 * and state, and a logger.
 *
 * @return array{FakeReadModelStore, RecordingLogger}
 */
function rebuildCommandWith(ActorClass $class = ActorClass::Service, ActorState $state = ActorState::Active, int $chunkSize = 2): array
{
    $identity = new FakeIdentity;
    $actor = $identity->addActor($class, $state)->id;
    $store = new FakeReadModelStore;
    $logger = new RecordingLogger;

    foreach ([1, 2, 3] as $number) {
        $store->add(NoteType::definition(), RebuildWorld::entry($number));
    }

    app()->instance(RebuildReadModels::class, new RebuildReadModels(
        new FakeTypeCatalog(NoteType::definition()),
        $identity->directory(),
        new FakeAccessContexts,
        $store,
        new FakeOperationRunner,
        new RebuildSettings($actor, $chunkSize),
    ));
    app()->instance(Clock::class, new FakeClock(new DateTimeImmutable('2026-09-30T12:34:56Z')));
    app()->instance(LoggerInterface::class, $logger);

    return [$store, $logger];
}

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array{int, list<string>}
 */
function runRebuildCommand(array $arguments): array
{
    $status = Artisan::call('cms:types:rebuild', $arguments);
    $lines = array_values(array_filter(array_map(rtrim(...), explode("\n", Artisan::output())), static fn (string $line): bool => $line !== ''));

    return [$status, $lines];
}

function rebuildCommandRange(int $first, int $last): string
{
    return new EntryRange(RebuildWorld::entry($first), RebuildWorld::entry($last))->chunk()->value;
}

it('rebuilds the type, prints each chunk and the operation, and logs it', function (): void {
    [$store, $logger] = rebuildCommandWith();

    [$status, $lines] = runRebuildCommand(['type' => 'test:note', '--run' => 'nightly']);

    expect($status)->toBe(0)
        ->and($lines)->toHaveCount(3)
        ->and($lines[0])->toStartWith('rebuilt '.rebuildCommandRange(1, 2).': 2 entries, 2 variants in ')
        ->and($lines[1])->toStartWith('rebuilt '.rebuildCommandRange(3, 3).': 1 entries, 1 variants in ')
        ->and($lines[2])->toContain('The rebuild nightly of test:note as ')
        ->and($lines[2])->toContain('is completed: 3 entries in 2 chunks this run, 2 of 2 chunks completed.')
        ->and($store->rebuiltChunks())->toBe([rebuildCommandRange(1, 2), rebuildCommandRange(3, 3)])
        ->and($logger->records[0][1])->toBe('A type\'s read model was rebuilt.')
        ->and($logger->records[0][2]['key'] ?? null)->toBe('test:note@nightly')
        ->and($logger->records[0][2]['entries'] ?? null)->toBe(3)
        ->and(array_keys($logger->records[0][2]))->toBe(['type', 'key', 'actor', 'state', 'entries', 'chunks', 'longest_ms'])
        ->and([$logger->records[0][2]['type'], $logger->records[0][2]['state'], $logger->records[0][2]['chunks']])->toBe(['test:note', 'completed', 2])
        ->and($lines[2])->toContain(' as '.(is_string($logger->records[0][2]['actor']) ? $logger->records[0][2]['actor'] : '').' is completed');
});

it('names the run by the Clock\'s time when --run is left out, and a second run with the name does nothing again', function (): void {
    [$store] = rebuildCommandWith();

    [$first, $lines] = runRebuildCommand(['type' => 'test:note']);
    [$second, $again] = runRebuildCommand(['type' => 'test:note', '--run' => '20260930T123456Z']);

    expect([$first, $second])->toBe([0, 0])
        ->and($lines[2])->toContain('The rebuild 20260930T123456Z of test:note')
        ->and($again)->toHaveCount(1)
        ->and($again[0])->toContain('is completed: 0 entries in 0 chunks this run, 2 of 2 chunks completed.')
        ->and($store->rebuiltChunks())->toHaveCount(2);
});

it('exits 64 for a type or run name it cannot read, and rebuilds nothing', function (array $arguments, string $message): void {
    [$store] = rebuildCommandWith();

    [$status, $lines] = runRebuildCommand($arguments);

    expect($status)->toBe(64)
        ->and(implode("\n", $lines))->toContain($message)
        ->and($store->planned)->toBe([]);
})->with([
    'not a type name' => [['type' => 'note'], '"note" is not a type name'],
    'a run name with a space' => [['type' => 'test:note', '--run' => 'two words'], '--run names the run'],
]);

it('exits with the catalog\'s code when the rebuild is refused', function (?ActorClass $class, ActorState $state, string $type, int $exit, string $message): void {
    [$store, $logger] = rebuildCommandWith($class ?? ActorClass::Service, $state);

    [$status, $lines] = runRebuildCommand(['type' => $type, '--run' => 'nightly']);

    expect($status)->toBe($exit)
        ->and(implode("\n", $lines))->toContain($message)
        ->and($logger->records[0][0])->toBe('error')
        ->and($logger->records[0][1])->toBe('The rebuild of a type\'s read model stopped.')
        ->and(array_keys($logger->records[0][2]))->toBe(['code', 'type', 'key'])
        ->and([$logger->records[0][2]['type'], $logger->records[0][2]['key']])->toBe([$type, $type.'@nightly'])
        ->and($store->rebuilt)->toBe([]);
})->with([
    'an unknown type' => [null, ActorState::Active, 'app:missing', 65, 'No type of this installation is named app:missing'],
    'a staff actor' => [ActorClass::Staff, ActorState::Active, 'test:note', 78, 'is a staff actor'],
    'a deactivated service actor' => [null, ActorState::Deactivated, 'test:note', 77, 'is deactivated, not active'],
]);

it('exits 65 at a payload at another schema version, says how to resume, and resumes with the same run', function (): void {
    [$store] = rebuildCommandWith();
    $store->add(NoteType::definition(), RebuildWorld::entry(3), NoteType::definition()->version + 1);

    [$stopped, $lines] = runRebuildCommand(['type' => 'test:note', '--run' => 'nightly']);
    $store->add(NoteType::definition(), RebuildWorld::entry(3));
    [$resumed, $after] = runRebuildCommand(['type' => 'test:note', '--run' => 'nightly']);

    expect($stopped)->toBe(65)
        ->and(implode("\n", $lines))->toContain('at schema version 4')
        ->and(implode("\n", $lines))->toContain('run again with --run=nightly to resume at the chunk that stopped')
        ->and($resumed)->toBe(0)
        ->and($after[0])->toStartWith('rebuilt '.rebuildCommandRange(3, 3))
        ->and($store->rebuiltChunks())->toBe([rebuildCommandRange(1, 2), rebuildCommandRange(3, 3)]);
});
