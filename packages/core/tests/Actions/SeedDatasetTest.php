<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Seeding\Actions\SeedChunks;
use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedReport;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Seeding\Domain\SeedRefused;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Operations\Fakes\FakeOperationRunner;
use Cbox\Cms\Core\Tests\Seeding\Fakes\FakeSeedTargets;
use Cbox\Cms\Core\Tests\Seeding\SeedActionWorld;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use LogicException;

/*
 * SeedDataset, the action behind cms:seed-scale (GUARDRAILS 4.2, 4.3, 9), with fakes: the service
 * actor it runs as, the nodes it reaches and the catalog's types it can seed, and its chunks as an
 * operation, each a changeset of seed.entries through the seeder's pipeline, which a run with the
 * same request does not run again.
 */

it('seeds the chunks of the run as an operation, one changeset each, and runs a completed run no more', function (): void {
    $world = new SeedActionWorld;
    $runner = new FakeOperationRunner;
    $request = new SeedRequest(SeedProfiles::small(), 1, 250);

    $report = $world->dataset(runner: $runner)->run($request);
    $again = $world->dataset(runner: $runner)->run($request);
    $sizes = array_map(static fn (PendingChangeset $changeset): int => $changeset->input instanceof SeedEntries ? count($changeset->input->entries) : 0, $world->committer->pending);

    expect($report->operation->state)->toBe(OperationState::Completed)
        ->and($report->operation->key->value)->toBe('small@1:1:250')
        ->and($report->operation->kind->value)->toBe('cms.seed')
        ->and($report->operation->completedNames())->toBe(['chunk-0', 'chunk-1', 'chunk-2'])
        ->and($sizes)->toBe([100, 100, 50])
        ->and($world->committer->pending[2]->envelope->unitOfWork?->value)->toBe('seed:small@1:1:2:50')
        ->and(array_map(static fn (SeedableType $type): string => $type->definition->name->value, $report->scope->catalog->types))->toBe(['test:draft_note', 'test:locked'])
        ->and($report->scope->nodes)->toHaveCount(2)
        ->and($again->operation->id->equals($report->operation->id))->toBeTrue()
        ->and($world->committer->pending)->toHaveCount(3);
});

it('skips the chunks whose entries all exist, once their idempotency records are gone, and seeds the rest', function (): void {
    $first = new SeedActionWorld;
    $first->dataset()->run(new SeedRequest(SeedProfiles::small(), 7, 200));
    $seeded = [];

    foreach ($first->committer->pending as $changeset) {
        $seeded = [...$seeded, ...($changeset->input instanceof SeedEntries ? $changeset->input->entries : [])];
    }

    // The same seed again with more entries, in a world with the entries of the first run but none
    // of its idempotency records, as after they expired or their partitions were dropped.
    $world = new SeedActionWorld()->exists(...$seeded);
    $report = $world->dataset()->run(new SeedRequest(SeedProfiles::small(), 7, 400));
    $units = array_map(static fn (PendingChangeset $changeset): ?string => $changeset->envelope->unitOfWork?->value, $world->committer->pending);
    $created = $world->committer->pending[0]->input instanceof SeedEntries ? $world->committer->pending[0]->input->entries[0]->entry : null;

    expect($seeded)->toHaveCount(200)
        ->and($report->operation->state)->toBe(OperationState::Completed)
        ->and($report->operation->completedNames())->toBe(['chunk-0', 'chunk-1', 'chunk-2', 'chunk-3'])
        ->and($units)->toBe(['seed:small@1:7:2:100', 'seed:small@1:7:3:100'])
        ->and($created?->value->unixMilliseconds())->toBe(Uuid7::unixMillisecondsOf(SeedProfiles::small()->anchor) + 200)
        ->and($world->targets?->entryReads)->toBe(4);
});

it('refuses a service actor that is not configured, unknown, not a service or not active', function (): void {
    $world = new SeedActionWorld;
    $staff = $world->identity->addActor(ActorClass::Staff)->id;
    $inactive = $world->identity->addActor(ActorClass::Service, ActorState::Deactivated)->id;
    $request = new SeedRequest(SeedProfiles::small(), 1, 10);
    $refusal = static function (SeedDataset $dataset) use ($request): SeedRefused {
        try {
            $dataset->run($request);
        } catch (SeedRefused $refused) {
            return $refused;
        }

        throw new LogicException('The run was not refused.');
    };

    $unconfigured = new SeedDataset(new SeedSettings(null), $world->identity, new FakeAccessContexts, new FakeSeedTargets, SeedActionWorld::types(), SeedActionWorld::validators(), new FakeOperationRunner, $world->pipeline());

    expect($refusal($unconfigured)->exit)->toBe(ExitCode::Config)
        ->and($refusal($world->dataset(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000047c9')))->getMessage())->toContain('does not exist')
        ->and($refusal($world->dataset($staff))->getMessage())->toContain('is a staff actor')
        ->and($refusal($world->dataset($inactive))->exit)->toBe(ExitCode::NoPerm)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a run with no node to seed below or no type to seed', function (): void {
    $world = new SeedActionWorld;
    $request = new SeedRequest(SeedProfiles::small(), 1, 10);

    expect(fn (): SeedReport => $world->dataset(nodes: false)->run($request))->toThrow(SeedRefused::class, 'reaches no node')
        ->and(fn (): SeedReport => $world->dataset(types: new FakeTypeCatalog)->run($request))->toThrow(SeedRefused::class, 'the catalog has no types');
});

it('stops at a chunk the kernel rejects, naming its unit of work, and leaves the operation to resume there', function (): void {
    $world = new SeedActionWorld;
    $runner = new FakeOperationRunner;
    $dataset = $world->dataset(runner: $runner);
    $world->reader->withoutNode(SeedActionWorld::home())->withoutNode(NodeId::fromString(SeedActionWorld::SECOND));

    expect(fn (): SeedReport => $dataset->run(new SeedRequest(SeedProfiles::small(), 3, 5)))
        ->toThrow(SeedRefused::class, 'The kernel rejected the seed chunk seed:small@1:3:0:5: validation_failed')
        ->and($world->committer->pending)->toBe([]);

    try {
        $dataset->run(new SeedRequest(SeedProfiles::small(), 3, 5));
    } catch (SeedRefused $refused) {
        expect($refused->exit)->toBe(ExitCode::Software);
    }
});

it('plans a chunk per profile chunk, and refuses a chunk it did not plan', function (): void {
    $world = new SeedActionWorld;
    $request = new SeedRequest(SeedProfiles::small(), 1, 201);
    $scope = $world->dataset()->scope();
    $chunks = new SeedChunks($request, $scope, $world->pipeline(), $world->targets ?? new FakeSeedTargets);

    expect($chunks->kind()->value)->toBe('cms.seed')
        ->and($chunks->chunks()->names())->toBe(['chunk-0', 'chunk-1', 'chunk-2'])
        ->and(count($chunks->command(2)->entries))->toBe(1)
        ->and($chunks->command(1)->entries[0]->entry->value->unixMilliseconds())->toBe(Uuid7::unixMillisecondsOf(SeedProfiles::small()->anchor) + 100);

    foreach (['chunk-3', 'chunk-01', 'chunk-', 'batch-1', 'chunk-x'] as $name) {
        expect(fn () => $chunks->runChunk(new ChunkName($name)))->toThrow(InvalidOperation::class);
    }

    $chunks->runChunk(new ChunkName('chunk-2'));

    expect($world->committer->pending)->toHaveCount(1)
        ->and($world->committer->pending[0]->envelope->unitOfWork?->value)->toBe('seed:small@1:1:2:1')
        ->and($world->committer->pending[0]->envelope->correlationId->value)->toBe('seed:small@1:1');
});
