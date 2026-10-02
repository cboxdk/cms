<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.create's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.4, 6.2): a create
 * reads the entry as absent and the home node at its version and plans the entry, the first
 * revision of its shared variant and the head on it, for the type the command names. It is
 * rejected for fields that break the type's rules, a value for an encrypted field, a type the
 * installation does not have and a home node that does not exist, and it is version_conflict for
 * an id that exists and for a read that went stale before the commit.
 */

/**
 * @return list<string>
 */
function createEntryCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

it('plans the entry, its first revision and its head, and reads the entry as absent and the home at its version', function (): void {
    $world = new EntryActionWorld;
    $fields = EntryActionWorld::fields('Groceries');

    $result = $world->create($fields);
    $pending = $world->committed();
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());
    [$entry, $revision, $head] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.create')
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $pending->plan->mutations()))->toBe([EntryCreated::class, RevisionCreated::class, HeadMoved::class])
        ->and($entry)->toEqual(new EntryCreated(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home()))
        ->and($revision instanceof RevisionCreated ? [$revision->variant->value, $revision->revision->value, $revision->type->toString(), $revision->fields->equals($fields)] : [])->toBe(['shared', 1, EntryActionWorld::type()->toString(), true])
        ->and($head instanceof HeadMoved ? [$head->from, $head->to->value] : [])->toBe([null, 1])
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::absent(EntryActionWorld::entry()))
        ->and($pending->reads->of($variant))->toEqual(ReadVersion::absent($variant))
        ->and($pending->reads->of(EntryActionWorld::home()))->toEqual(ReadVersion::at(EntryActionWorld::home(), new AggregateVersion(1)));
});

it('accepts an encrypted field left empty', function (): void {
    $world = new EntryActionWorld;

    expect($world->create(EntryActionWorld::fields('Groceries', ['secret' => new NullValue]))->outcome())->toBe(Outcome::Committed);
});

it('rejects fields that break the type\'s rules and commits nothing', function (): void {
    $world = new EntryActionWorld;

    $result = $world->create(EntryActionWorld::fields('Groceries', ['colour' => new TextValue('green')]));

    expect(createEntryCodes($result))->toBe(['validation_failed', 'validation_unknown_field'])
        ->and($result->errors[1]->path?->toString())->toBe('fields.colour')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a value for an encrypted field, which it cannot store until keys exist', function (): void {
    $world = new EntryActionWorld;

    $result = $world->create(EntryActionWorld::fields('Groceries', ['secret' => new TextValue('the door code')]));

    expect(createEntryCodes($result))->toBe(['validation_failed', 'field_encryption_unavailable'])
        ->and($result->errors[1]->path?->toString())->toBe('fields.secret')
        ->and($result->errors[1]->message)->not->toContain('the door code')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a type the installation does not have', function (): void {
    $world = new EntryActionWorld;

    $result = $world->create(EntryActionWorld::fields('Groceries'), type: TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003d9'));

    expect(createEntryCodes($result))->toBe(['validation_failed', 'validation_failed'])
        ->and($result->errors[1]->message)->toContain('01936f5e-8a2b-7c3d-9e4f-0000000003d9')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a home node that does not exist or the actor cannot reach', function (): void {
    $world = new EntryActionWorld;
    $nowhere = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003a9');

    $result = $world->create(EntryActionWorld::fields('Groceries'), home: $nowhere);

    expect(createEntryCodes($result))->toBe(['validation_failed', 'validation_failed'])
        ->and($result->errors[1]->message)->toContain($nowhere->toString())
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for an entry id that exists', function (): void {
    $world = new EntryActionWorld;
    $world->entries->withEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(1));

    $result = $world->create(EntryActionWorld::fields('Groceries'));

    expect(createEntryCodes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('entry:'.EntryActionWorld::ENTRY)
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when the entry was created by another call before the commit', function (): void {
    $world = new EntryActionWorld;
    $world->committer->at(EntryActionWorld::entry(), new AggregateVersion(1));

    $result = $world->create(EntryActionWorld::fields('Groceries'));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(createEntryCodes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('changed after it was read')
        ->and($result->errors[0]->message)->toContain('entry:'.EntryActionWorld::ENTRY);
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new EntryActionWorld;
    $world->create(EntryActionWorld::fields('Groceries'));
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new CreateEntryAction($world->entries))->getAttributes(Action::class),
    );

    expect($world->committed()->command->value)->toBe('entry.create')
        ->and($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
