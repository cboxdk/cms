<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyTable;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;

/*
 * PRD 6.1, M1 point 3: a write repeated with one idempotency key commits once.
 * The command is the test-only tally.add through the real command pipeline on Postgres, with the
 * real command transaction, idempotency store, receipt store and commit. Five calls with one key
 * and the same content give one changeset, one set of mutations, events and audit, and the first
 * call's receipt every time; the same key with other content is rejected with
 * idempotency_conflict and changes nothing. The same holds for entry.create, the kernel's command
 * that creates an entry of a workbench type, through the real pipeline with its reads and writers.
 */

afterEach(function (): void {
    TallyWorld::cleanUp();
    EntryWorld::cleanUp();
});

it('commits five repeats with one key as one changeset and answers each with the first receipt', function (): void {
    $world = new TallyWorld;

    $results = array_map(
        static fn (int $attempt): WriteResult => $world->add(new AddTally(TallyWorld::tally(), 2), 'repeat-me'),
        range(1, 5),
    );

    $changesets = array_map(static fn (WriteResult $result): string => $result->receipt->changesetId instanceof ChangesetId ? $result->receipt->changesetId->toString() : '-', $results);
    $positions = array_map(static fn (WriteResult $result): string => $result->receipt->position->value ?? '-', $results);

    expect(array_map(static fn (WriteResult $result): Outcome => $result->outcome(), $results))->each->toBe(Outcome::Committed)
        ->and(array_unique($changesets))->toHaveCount(1)
        ->and($changesets[0])->not->toBe('-')
        ->and(array_unique($positions))->toHaveCount(1)
        ->and(TallyWorld::rows())->toBe([
            'changeset_register' => 1, 'changesets' => 1, 'changeset_principals' => 0, 'changeset_reason_texts' => 0, 'audit' => 1,
            'events' => 1, 'idempotency_keys' => 1, 'receipts' => 1, 'receipt_projections' => 1, TallyTable::TABLE => 1,
        ])
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([1, 2]);
});

it('rejects the same key with other content as idempotency_conflict and changes nothing', function (): void {
    $world = new TallyWorld;
    $first = $world->add(new AddTally(TallyWorld::tally(), 2), 'repeat-me');
    $before = TallyWorld::rows();

    $other = $world->add(new AddTally(TallyWorld::tally(), 3), 'repeat-me');

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($other->outcome())->toBe(Outcome::Rejected)
        ->and(array_map(static fn (CatalogError $error): string => $error->code->value, $other->errors))->toBe(['idempotency_conflict'])
        ->and($other->receipt->changesetId)->toBeNull()
        ->and(TallyWorld::rows())->toBe($before)
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([1, 2]);
});

it('commits the same content under another key as a changeset of its own', function (): void {
    $world = new TallyWorld;

    $world->add(new AddTally(TallyWorld::tally(), 2), 'repeat-me');
    $second = $world->add(new AddTally(TallyWorld::tally(), 2), 'another-key');

    expect($second->outcome())->toBe(Outcome::Committed)
        ->and(TallyWorld::rows()['changesets'])->toBe(2)
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([2, 4]);
});

it('creates an entry once for five repeats of entry.create with one key, and answers each with the first receipt', function (): void {
    EntryWorld::seed();
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    $results = array_map(
        static fn (int $attempt): WriteResult => $world->create($type->id, EntryFields::article(), 'create-repeat'),
        range(1, 5),
    );

    $changesets = array_map(static fn (WriteResult $result): string => $result->receipt->changesetId instanceof ChangesetId ? $result->receipt->changesetId->toString() : '-', $results);

    expect(array_map(static fn (WriteResult $result): Outcome => $result->outcome(), $results))->each->toBe(Outcome::Committed)
        ->and(array_unique($changesets))->toHaveCount(1)
        ->and($changesets[0])->not->toBe('-')
        ->and(EntryWorld::rows())->toBe([
            'changesets' => 1, 'audit' => 1, 'events' => 2, 'idempotency_keys' => 1, 'receipts' => 1,
            'entries' => 1, 'variant_heads' => 1, 'revisions' => 1, 'revision_payloads' => 1, 'head_snapshots' => 0,
        ]);
});

it('rejects entry.create with a used key and other content as idempotency_conflict and creates nothing', function (): void {
    EntryWorld::seed();
    $world = new EntryWorld;
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $first = $world->create($type->id, EntryFields::article(), 'create-repeat');
    $before = EntryWorld::rows();

    $other = $world->create($type->id, EntryFields::article('Other content'), 'create-repeat');

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($other->outcome())->toBe(Outcome::Rejected)
        ->and(array_map(static fn (CatalogError $error): string => $error->code->value, $other->errors))->toBe(['idempotency_conflict'])
        ->and($other->receipt->changesetId)->toBeNull()
        ->and(EntryWorld::rows())->toBe($before);
});
