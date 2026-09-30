<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\ReadModels\Actions\RebuildChunks;
use Cbox\Cms\Core\ReadModels\Actions\RebuildReadModels;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildReport;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Core\Tests\Operations\Fakes\FakeOperationRunner;
use Cbox\Cms\Core\Tests\ReadModels\Fakes\FakeReadModelStore;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use RuntimeException;

/*
 * The rebuild action called directly with its DTO and fakes (GUARDRAILS 9): the type catalog, the
 * identity, the access contexts, the ReadModelStore (held to the Postgres store by
 * ReadModelStoreBehaviour) and the OperationRunner (held to laravel-operations by
 * OperationRunnerBehaviour). It covers a rebuild in chunks as the service actor's context, the
 * refusals before anything is read, a chunk that stops at a payload at another schema version,
 * and the run that resumes it where it stopped.
 */

/**
 * @return array{RebuildReadModels, FakeReadModelStore, FakeOperationRunner, FakeAccessContexts, ActorId}
 */
function rebuildAction(?int $chunkSize = 2, ActorClass $class = ActorClass::Service, ActorState $state = ActorState::Active, bool $configured = true): array
{
    $identity = new FakeIdentity;
    $actor = $identity->addActor($class, $state)->id;
    $store = new FakeReadModelStore;
    $runner = new FakeOperationRunner;
    $contexts = new FakeAccessContexts()->grant($actor, ClassificationAccess::Sensitive, new AccessRegion(new NodePath('a1')));

    foreach ([3, 1, 5, 2, 4] as $number) {
        $store->add(NoteType::definition(), RebuildWorld::entry($number));
    }

    return [
        new RebuildReadModels(new FakeTypeCatalog(NoteType::definition()), $identity->directory(), $contexts, $store, $runner, new RebuildSettings($configured ? $actor : null, $chunkSize ?? 100)),
        $store,
        $runner,
        $contexts,
        $actor,
    ];
}

function rebuildNote(RebuildReadModels $action, string $run = 'nightly'): mixed
{
    try {
        return $action->rebuild(new RebuildRequest(new TypeName('test:note'), $run));
    } catch (RebuildRefused $refused) {
        return $refused;
    }
}

function rebuildRange(int $first, int $last): string
{
    return new EntryRange(RebuildWorld::entry($first), RebuildWorld::entry($last))->chunk()->value;
}

it('rebuilds the type in chunks of the chunk size as an operation, under the service actor\'s context', function (): void {
    [$action, $store, $runner, $contexts, $actor] = rebuildAction();

    $report = $action->rebuild(new RebuildRequest(new TypeName('test:note'), 'nightly'));
    $principal = new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive);
    $access = new AccessContext($principal, [new AccessRegion(new NodePath('a1'))], ClassificationAccess::Sensitive);

    expect($report->operation->state)->toBe(OperationState::Completed)
        ->and($report->operation->key->value)->toBe('test:note@nightly')
        ->and($report->operation->kind->value)->toBe('type_tables.rebuild')
        ->and($report->operation->completedNames())->toBe([rebuildRange(1, 2), rebuildRange(3, 4), rebuildRange(5, 5)])
        ->and(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $report->chunks))->toBe([2, 2, 1])
        ->and($report->entries())->toBe(5)
        ->and($report->type->value)->toBe('test:note')
        ->and($report->actor->equals($actor))->toBeTrue()
        ->and($store->rebuiltChunks())->toBe([rebuildRange(1, 2), rebuildRange(3, 4), rebuildRange(5, 5)])
        ->and($contexts->asked)->toEqual([$principal])
        ->and(array_map(static fn (array $rebuilt): AccessContext => $rebuilt[1], $store->rebuilt))->toEqual([$access, $access, $access])
        ->and(array_map(static fn (array $plan): int => $plan[1], $store->planned))->toBe([2])
        ->and($runner->find(new OperationKind(RebuildChunks::KIND), $report->operation->key)?->state)->toBe(OperationState::Completed);
});

it('runs nothing again for a run that completed, and a new run name rebuilds again', function (): void {
    [$action, $store] = rebuildAction(chunkSize: 5);
    $action->rebuild(new RebuildRequest(new TypeName('test:note'), 'nightly'));

    $again = $action->rebuild(new RebuildRequest(new TypeName('test:note'), 'nightly'));
    $other = $action->rebuild(new RebuildRequest(new TypeName('test:note'), 'another'));

    expect($again->chunks)->toBe([])
        ->and($again->operation->state)->toBe(OperationState::Completed)
        ->and(count($store->planned))->toBe(2)
        ->and($other->entries())->toBe(5)
        ->and($store->rebuiltChunks())->toBe([rebuildRange(1, 5), rebuildRange(1, 5)]);
});

it('refuses an unknown type and a service actor it cannot run as, before it reads anything', function (RebuildReadModels $action, FakeReadModelStore $store, FakeAccessContexts $contexts, string $code, string $message, string $type): void {
    try {
        $action->rebuild(new RebuildRequest(new TypeName($type), 'nightly'));
        $refused = null;
    } catch (RebuildRefused $exception) {
        $refused = $exception;
    }

    expect($refused?->errorCode)->toBe($code)
        ->and($refused?->getMessage())->toContain($message)
        ->and($store->planned)->toBe([])
        ->and($store->rebuilt)->toBe([])
        ->and($contexts->asked)->toBe([]);
})->with([
    'an unknown type' => function (): array {
        [$action, $store, , $contexts] = rebuildAction();

        return [$action, $store, $contexts, 'rebuild_type_unknown', 'is named app:missing', 'app:missing'];
    },
    'no service actor configured' => function (): array {
        [$action, $store, , $contexts] = rebuildAction(configured: false);

        return [$action, $store, $contexts, 'rebuild_identity_invalid', 'names none', 'test:note'];
    },
    'a staff actor' => function (): array {
        [$action, $store, , $contexts] = rebuildAction(class: ActorClass::Staff);

        return [$action, $store, $contexts, 'rebuild_identity_invalid', 'is a staff actor', 'test:note'];
    },
    'a deactivated service actor' => function (): array {
        [$action, $store, , $contexts] = rebuildAction(state: ActorState::Deactivated);

        return [$action, $store, $contexts, 'actor_not_active', 'is deactivated, not active', 'test:note'];
    },
]);

it('refuses a service actor that does not exist', function (): void {
    $missing = ActorId::fromString('0192a0c0-0000-7000-8000-0000000000ff');
    $store = new FakeReadModelStore;
    $action = new RebuildReadModels(new FakeTypeCatalog(NoteType::definition()), new FakeIdentity()->directory(), new FakeAccessContexts, $store, new FakeOperationRunner, new RebuildSettings($missing));

    expect(rebuildNote($action))->toBeInstanceOf(RebuildRefused::class)
        ->and(rebuildNote($action) instanceof RebuildRefused ? rebuildNote($action)->errorCode : null)->toBe('rebuild_identity_invalid')
        ->and($store->planned)->toBe([]);
});

it('stops at the chunk with a payload at another schema version, and a second run resumes there with the plan it started with', function (): void {
    [$action, $store, $runner] = rebuildAction();
    $store->add(NoteType::definition(), RebuildWorld::entry(3), NoteType::definition()->version - 1);

    $stopped = rebuildNote($action);
    $progress = $runner->find(new OperationKind(RebuildChunks::KIND), new RebuildRequest(new TypeName('test:note'), 'nightly')->key);

    // The payload is readable again, and an entry created since falls in no chunk of the plan.
    $store->add(NoteType::definition(), RebuildWorld::entry(3));
    $store->add(NoteType::definition(), RebuildWorld::entry(9));
    $resumed = rebuildNote($action);

    expect($stopped)->toBeInstanceOf(RebuildRefused::class)
        ->and($stopped instanceof RebuildRefused ? $stopped->errorCode : null)->toBe('rebuild_schema_version_unsupported')
        ->and($progress?->state)->toBe(OperationState::Running)
        ->and($progress?->completedNames())->toBe([rebuildRange(1, 2)])
        ->and($resumed)->toBeInstanceOf(RebuildReport::class)
        ->and(count($store->planned))->toBe(1)
        ->and($store->rebuiltChunks())->toBe([rebuildRange(1, 2), rebuildRange(3, 4), rebuildRange(5, 5)]);
});

it('lets what a chunk throws stop the run, and resumes at that chunk', function (): void {
    [$action, $store] = rebuildAction();
    $store->before(new EntryRange(RebuildWorld::entry(5), RebuildWorld::entry(5)), static function (): void {
        throw new RuntimeException('The database went away for a moment.');
    });

    expect(static fn (): mixed => rebuildNote($action))->toThrow(RuntimeException::class, 'went away');

    $resumed = rebuildNote($action);

    expect($store->rebuiltChunks())->toBe([rebuildRange(1, 2), rebuildRange(3, 4), rebuildRange(5, 5)])
        ->and($resumed instanceof RebuildReport ? $resumed->chunks : null)->toHaveCount(1);
});
