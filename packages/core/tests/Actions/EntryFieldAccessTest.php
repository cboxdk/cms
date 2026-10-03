<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Tests\Entries\BriefType;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use LogicException;

/*
 * A writer sets only the fields it may read (PRD 2.31, 6.2, 12.2; security review S-2 and S-3),
 * with entry.create and entry.revise in the command pipeline with fakes (GUARDRAILS 9), on the test
 * type test:brief: a public title, an internal `notes` open to agents, an internal `contact` closed
 * to agents, and a public group `place` whose nested `code` is closed to agents. An actor whose
 * classification access is public may not set `notes` or `contact`, and an agent with internal
 * access may not set `contact` or `place.code`: a value for one is unauthorized at its path. A
 * revise replaces every field, so each field the writer may not read keeps the value the head's
 * revision holds, for a type with full history and one whose history is audit-only.
 */

/**
 * A world with test:brief of the history in its catalog, unless $stored is false an entry of it at
 * version 2 whose shared variant is at version 6 on revision 4 with every field set, and the caller
 * of the case: an agent with internal access, or a staff member with public access.
 *
 * @param  string  $caller  'agent', or 'public' for a staff member
 */
function briefWorld(string $caller, History $history = History::Full, bool $stored = true): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->types = [BriefType::definition($history)];
    $world->validators = [new BriefType];

    if (! $stored) {
        return briefCaller($world, $caller);
    }

    $world->entries
        ->withEntry(EntryActionWorld::entry(), BriefType::definition()->id, EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));
    $head = $history === History::Full ? $world->revisions->with(...) : $world->revisions->snapshot(...);
    $head(EntryActionWorld::entry(), VariantKey::shared(), new RevisionNumber(4), 1, briefFields([
        'title' => new TextValue('Stored title'),
        'notes' => new TextValue('stored notes'),
        'contact' => new TextValue('desk@example.org'),
        'place' => briefPlace('Harbour', 'H-7'),
    ]));

    return briefCaller($world, $caller);
}

/**
 * @param  string  $caller  'agent', or 'public' for a staff member
 */
function briefCaller(EntryActionWorld $world, string $caller): EntryActionWorld
{
    if ($caller === 'agent') {
        $world->credential = IssuerKind::Agent;
        $world->issuer = EnvelopeIssuer::Agent;
        $world->access = ClassificationAccess::Internal;
    } else {
        $world->access = ClassificationAccess::Public;
    }

    return $world;
}

/**
 * @param  array<string, FieldValue>  $fields
 */
function briefFields(array $fields): FieldValues
{
    $named = [];

    foreach ($fields as $handle => $value) {
        $named[] = new NamedValue(new FieldHandle($handle), $value);
    }

    return new FieldValues(new FieldMap(...$named));
}

function briefPlace(?string $name, ?string $code): GroupValue
{
    $named = [];

    if ($name !== null) {
        $named[] = new NamedValue(new FieldHandle('name'), new TextValue($name));
    }

    if ($code !== null) {
        $named[] = new NamedValue(new FieldHandle('code'), new TextValue($code));
    }

    return new GroupValue(new FieldMap(...$named));
}

/**
 * @return list<string> each error as "<code> <path>"
 */
function briefErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'),
        $result->errors,
    );
}

/**
 * The fields of the one revision the committer was asked to commit.
 */
function briefCommitted(EntryActionWorld $world): FieldValues
{
    foreach ($world->committed()->plan->mutations() as $mutation) {
        if ($mutation instanceof RevisionCreated) {
            return $mutation->fields;
        }
    }

    throw new LogicException('The committed plan writes no revision.');
}

it('refuses a value for a field the caller may not read at its path, on create and on revise, and commits nothing', function (string $caller, string $field, FieldValue $value, string $path): void {
    $creator = briefWorld($caller, stored: false);
    $reviser = briefWorld($caller);
    $fields = briefFields(['title' => new TextValue('A brief'), $field => $value]);

    $created = $creator->create($fields, BriefType::definition()->id);
    $revised = $reviser->revise(6, $fields);

    expect(briefErrors($created))->toBe(['unauthorized -', 'unauthorized '.$path])
        ->and(briefErrors($revised))->toBe(['unauthorized -', 'unauthorized '.$path])
        ->and($revised->errors[1]->message)->not->toContain('desk@example.org')
        ->and($creator->committer->pending)->toBe([])
        ->and($reviser->committer->pending)->toBe([]);
})->with([
    'an internal field from a public-ceiling actor' => ['public', 'notes', new TextValue('my notes'), 'fields.notes'],
    'an internal field closed to agents from a public-ceiling actor' => ['public', 'contact', new TextValue('desk@example.org'), 'fields.contact'],
    'a field closed to agents from an agent' => ['agent', 'contact', new TextValue('desk@example.org'), 'fields.contact'],
    'a nested field closed to agents from an agent' => ['agent', 'place', briefPlace('Quay', 'Q-1'), 'fields.place.code'],
]);

it('lets an agent with internal access set an internal field open to agents, and a public-ceiling actor a nested field closed only to agents', function (string $caller, string $field, FieldValue $value): void {
    $world = briefWorld($caller, stored: false);

    $result = $world->create(briefFields(['title' => new TextValue('A brief'), $field => $value]), BriefType::definition()->id);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(briefCommitted($world)->own->get(new FieldHandle($field)))->toEqual($value);
})->with([
    'notes from an agent' => ['agent', 'notes', new TextValue('agent notes')],
    'place.code from a public-ceiling actor' => ['public', 'place', briefPlace('Quay', 'Q-1')],
]);

it('keeps every field the caller may not read as the head holds it when it revises, for full and audit-only history', function (string $caller, FieldValues $sent, FieldValues $kept, History $history): void {
    $world = briefWorld($caller, $history);

    $result = $world->revise(6, $sent);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(briefCommitted($world)->equals($kept))->toBeTrue();
})->with([
    'a public-ceiling actor' => [
        'public',
        briefFields(['title' => new TextValue('New title'), 'place' => briefPlace('Quay', null)]),
        briefFields(['title' => new TextValue('New title'), 'place' => briefPlace('Quay', null), 'notes' => new TextValue('stored notes'), 'contact' => new TextValue('desk@example.org')]),
    ],
    'an agent' => [
        'agent',
        briefFields(['title' => new TextValue('New title'), 'notes' => new TextValue('agent notes'), 'place' => briefPlace('Quay', null)]),
        briefFields(['title' => new TextValue('New title'), 'notes' => new TextValue('agent notes'), 'place' => briefPlace('Quay', 'H-7'), 'contact' => new TextValue('desk@example.org')]),
    ],
])->with([
    'full history' => [History::Full],
    'audit-only history' => [History::AuditOnly],
]);

it('takes a null for a field the caller may not read as no value, and keeps the stored one', function (): void {
    $world = briefWorld('agent');

    $result = $world->revise(6, briefFields(['title' => new TextValue('New title'), 'contact' => new NullValue, 'place' => new GroupValue(new FieldMap(new NamedValue(new FieldHandle('code'), new NullValue)))]));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(briefCommitted($world)->equals(briefFields(['title' => new TextValue('New title'), 'contact' => new TextValue('desk@example.org'), 'place' => briefPlace(null, 'H-7')])))->toBeTrue();
});

it('changes nothing for a caller who may read every field: what it leaves out is gone', function (): void {
    $world = briefWorld('public');
    $world->access = ClassificationAccess::Internal;

    $result = $world->revise(6, briefFields(['title' => new TextValue('New title')]));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(briefCommitted($world)->equals(briefFields(['title' => new TextValue('New title')])))->toBeTrue();
});

it('rejects a revise that cannot keep the fields the caller may not read, because the head was written under another schema version', function (): void {
    $world = briefWorld('public');
    $world->revisions->with(EntryActionWorld::entry(), VariantKey::shared(), new RevisionNumber(4), 2, briefFields(['contact' => new TextValue('desk@example.org')]));

    $result = $world->revise(6, briefFields(['title' => new TextValue('New title')]));

    expect(briefErrors($result))->toBe(['validation_failed fields'])
        ->and($result->errors[0]->message)->toContain('schema version 2')
        ->and($result->errors[0]->message)->not->toContain('desk@example.org')
        ->and($world->committer->pending)->toBe([]);
});
