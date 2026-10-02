<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\ReviseEntryAction;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\ReviseEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * entry.revise's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.4, 6.2): a revise
 * reads the entry and the head of its shared variant and plans the revision after the variant's
 * highest number, the head's draft or a published revision a release wrote after it, and the
 * head's move to it, for the entry's own type. It is rejected for fields that break the type's
 * rules and a value for an encrypted field, and it is version_conflict when the variant is not at
 * the version the caller saw, when the entry does not exist, and when the variant changed before
 * the commit.
 */

/**
 * A world with the entry at version 2, the head of its shared variant at version 6 on revision 4.
 */
function reviseEntryWorld(): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->entries
        ->withEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));

    return $world;
}

/**
 * @return list<string>
 */
function reviseEntryCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

it('plans the revision after the head\'s and the head\'s move to it, and reads the entry and the variant at their versions', function (): void {
    $world = reviseEntryWorld();
    $fields = EntryActionWorld::fields('Groceries for Sunday');

    $result = $world->revise(6, $fields);
    $pending = $world->committed();
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());
    [$revision, $head] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('entry.revise')
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $pending->plan->mutations()))->toBe([RevisionCreated::class, HeadMoved::class])
        ->and($revision instanceof RevisionCreated ? [$revision->variant->value, $revision->revision->value, $revision->type->toString(), $revision->fields->equals($fields)] : [])->toBe(['shared', 5, EntryActionWorld::type()->toString(), true])
        ->and($head instanceof HeadMoved ? [$head->from?->value, $head->to->value] : [])->toBe([4, 5])
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::at(EntryActionWorld::entry(), new AggregateVersion(2)))
        ->and($pending->reads->of($variant))->toEqual(ReadVersion::at($variant, new AggregateVersion(6)));
});

it('numbers the revision after the published revision a release wrote after the draft, and moves the head from the draft', function (): void {
    $world = new EntryActionWorld;
    $world->entries
        ->withEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(7), new RevisionNumber(4), new RevisionNumber(5), new RevisionNumber(5)));

    $result = $world->revise(7, EntryActionWorld::fields('After the release'));
    [$revision, $head] = $world->committed()->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($revision instanceof RevisionCreated ? $revision->revision->value : null)->toBe(6)
        ->and($head instanceof HeadMoved ? [$head->from?->value, $head->to->value] : [])->toBe([4, 6]);
});

it('rejects fields that break the type\'s rules and commits nothing', function (): void {
    $world = reviseEntryWorld();

    $result = $world->revise(6, EntryActionWorld::fields('Groceries', ['colour' => new TextValue('green')]));

    expect(reviseEntryCodes($result))->toBe(['validation_failed', 'validation_unknown_field'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a value for an encrypted field', function (): void {
    $world = reviseEntryWorld();

    $result = $world->revise(6, EntryActionWorld::fields('Groceries', ['secret' => new TextValue('the door code')]));

    expect(reviseEntryCodes($result))->toBe(['validation_failed', 'field_encryption_unavailable'])
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when the variant is not at the version the caller saw', function (): void {
    $world = reviseEntryWorld();

    $result = $world->revise(5, EntryActionWorld::fields('Groceries'));

    expect(reviseEntryCodes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('variant:'.EntryActionWorld::ENTRY.':shared')
        ->and($result->errors[0]->message)->toContain('expected version 5, found version 6')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for an entry that does not exist', function (): void {
    $world = new EntryActionWorld;

    $result = $world->revise(1, EntryActionWorld::fields('Groceries'));

    expect(reviseEntryCodes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('expected version 1, found no aggregate')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another call moved the variant before the commit', function (): void {
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());
    $world = reviseEntryWorld()->commitWith(new VersionConflict(new StaleRead($variant, new AggregateVersion(6), new AggregateVersion(7))));

    $result = $world->revise(6, EntryActionWorld::fields('Groceries'));

    expect(reviseEntryCodes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('changed after it was read: expected version 6, found version 7');
});

it('refuses to plan a revision of an entry or a shared variant read as absent, which the kernel rejects before it plans, and is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new EntryActionWorld;
    $action = new ReviseEntryAction($world->entries);
    $command = new ReviseEntry(EntryActionWorld::entry(), new AggregateVersion(1), EntryActionWorld::fields('A note'));
    $headless = new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(1), null);
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass($action)->getAttributes(Action::class),
    );

    expect(static fn (): Plan => $action->plan($command, new ReviseEntryAggregates(EntryActionWorld::entry(), null)))->toThrow(LogicException::class, 'whose shared variant was read as absent')
        ->and(static fn (): Plan => $action->plan($command, new ReviseEntryAggregates(EntryActionWorld::entry(), $headless)))->toThrow(LogicException::class, 'whose shared variant was read as absent')
        ->and($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
