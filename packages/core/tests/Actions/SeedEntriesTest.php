<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Tests\Seeding\DraftNoteType;
use Cbox\Cms\Core\Tests\Seeding\LockedType;
use Cbox\Cms\Core\Tests\Seeding\SeedActionWorld;

/*
 * seed.entries through the command pipeline with fakes (GUARDRAILS 4.3, 9): one composed plan per
 * chunk, one step of every entry per sub-plan, entries that exist left out, a release validated as
 * the plan holds its revision, the seeder's authorization, a conflict, and a replay of its unit.
 */

/**
 * @return list<string>
 */
function seedCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.($error->path instanceof FieldPath ? ' '.$error->path->toString() : ''), $result->errors);
}

/**
 * @return list<string>
 */
function seedSteps(SeedActionWorld $world): array
{
    return array_map(static function (Mutation $mutation): string {
        $class = substr(strrchr($mutation::class, '\\') ?: $mutation::class, 1);

        preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $mutation->aggregate()->aggregateKey(), $id);

        return $class.' '.substr($id[0] ?? '', -3);
    }, $world->committed()->plan->mutations());
}

it('plans every entry of the chunk in one changeset, one step of every entry after the other', function (): void {
    $world = new SeedActionWorld;
    $result = $world->run(new SeedEntries(
        SeedActionWorld::entry(1, DraftNoteType::ID, ['title' => 'One', 'summary' => 'First'], release: true),
        SeedActionWorld::entry(2, LockedType::ID, ['code' => 'L-2']),
        SeedActionWorld::entry(3, DraftNoteType::ID, ['title' => 'Three']),
    ));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(seedSteps($world))->toBe([
            'EntryCreated 7e1', 'EntryCreated 7e2', 'EntryCreated 7e3',
            'RevisionCreated 7e1', 'RevisionCreated 7e2', 'RevisionCreated 7e3',
            'HeadMoved 7e1', 'HeadMoved 7e2', 'HeadMoved 7e3',
            'VariantReleased 7e1',
        ])
        ->and($world->committed()->command->value)->toBe('seed.entries')
        ->and($world->committed()->envelope->issuerKind->value)->toBe('seed')
        ->and($world->reader->reads)->toBe(2);

    $reads = $world->committed()->reads;
    $entry = SeedActionWorld::entry(1, DraftNoteType::ID, [])->entry;

    expect($reads->of($entry)?->version)->toBeNull()
        ->and($reads->of(new VariantRef($entry, VariantKey::shared()))?->version)->toBeNull()
        ->and($reads->of(SeedActionWorld::home())?->version)->toEqual(new AggregateVersion(1));
});

it('leaves out an entry of the chunk that exists, and changes nothing when every entry exists', function (): void {
    $world = new SeedActionWorld;
    $first = SeedActionWorld::entry(1, LockedType::ID, ['code' => 'L-1']);
    $second = SeedActionWorld::entry(2, LockedType::ID, ['code' => 'L-2']);
    $world->reader->withEntry($first->entry);

    $result = $world->run(new SeedEntries($first, $second));
    $mutations = $world->committed()->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $mutations))->toBe([EntryCreated::class, RevisionCreated::class, HeadMoved::class])
        ->and($mutations[0]->aggregate()->aggregateKey())->toBe($second->entry->aggregateKey());

    $world->reader->withEntry($second->entry);
    $reads = $world->reader->reads;
    $none = $world->run(new SeedEntries($first, $second), 'seed:test@1:1:1:2');

    expect($none->outcome())->toBe(Outcome::Rejected)
        ->and(seedCodes($none))->toBe(['validation_failed'])
        ->and($world->reader->reads - $reads)->toBe(1);
});

it('validates the release of a revision the chunk creates at the release stage, as the plan holds it', function (): void {
    $world = new SeedActionWorld;
    $result = $world->run(new SeedEntries(SeedActionWorld::entry(1, DraftNoteType::ID, ['title' => 'One'], release: true)));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(seedCodes($result))->toBe(['validation_failed', 'validation_required revision.summary'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an entry homed on a node out of the actor\'s reach and a field above its access', function (): void {
    $world = new SeedActionWorld;
    $away = $world->run(new SeedEntries(SeedActionWorld::entry(1, LockedType::ID, ['code' => 'L-1'], home: NodeId::fromString(SeedActionWorld::NOWHERE))));

    $world->access = ClassificationAccess::Public;
    $above = $world->run(new SeedEntries(SeedActionWorld::entry(2, LockedType::ID, ['code' => 'L-2'])), 'seed:test@1:1:1:1');

    expect(seedCodes($away))->toBe(['validation_failed', 'validation_failed'])
        ->and($away->errors[1]->message)->toContain('cannot be the home of the entry')
        ->and(seedCodes($above))->toBe(['unauthorized'])
        ->and($above->errors[0]->message)->toContain('"code" of test:locked, classified internal')
        ->and($world->committer->pending)->toBe([]);
});

it('answers version_conflict when an entry of the chunk was created after it was read', function (): void {
    $entry = SeedActionWorld::entry(1, LockedType::ID, ['code' => 'L-1']);
    $world = new SeedActionWorld()->commitWith(new VersionConflict(new StaleRead($entry->entry, null, new AggregateVersion(1))));

    $result = $world->run(new SeedEntries($entry));

    expect(seedCodes($result))->toBe(['version_conflict']);
});

it('replays a chunk whose unit of work ran before, and commits it once', function (): void {
    $world = new SeedActionWorld;
    $chunk = new SeedEntries(SeedActionWorld::entry(1, LockedType::ID, ['code' => 'L-1']));

    $first = $world->run($chunk);
    $again = $world->run($chunk);

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and($again->receipt->changesetId?->toString())->toBe($first->receipt->changesetId?->toString())
        ->and($world->committer->pending)->toHaveCount(1);
});
