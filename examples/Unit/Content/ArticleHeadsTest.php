<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Testkit\Content\FakeVariantHeadReader;

// An addon's write action that revises a variant reads its head first: the version the command
// expects, the revision the head points at, the highest number any revision has, which the next
// revision follows, and the draft's fields, which the new revision keeps but for what the addon
// changes. The reader is the VariantHeadReader contract; a test gives the fake the head to read.

const EXAMPLE_ENTRY = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a31';

const EXAMPLE_HOME = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a32';

const EXAMPLE_TYPE = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a33';

/**
 * The plan an addon makes to retitle the shared variant of an entry: the next revision after the
 * variant's highest number with the head's fields and the new title, and the head moved to it.
 * Null when the entry has no such head, or its draft cannot be read as the type is now, which
 * the kernel then rejects as version_conflict when the command expected the head.
 */
function retitlePlan(VariantHeadReader $heads, EntryId $entry, string $title): ?Plan
{
    $head = $heads->head($entry, VariantKey::shared());

    if (! $head instanceof VariantHead || ! $head->fields instanceof FieldValues) {
        return null;
    }

    $kept = array_values(array_filter($head->fields->own->fields, static fn (NamedValue $field): bool => $field->handle->value !== 'title'));
    $fields = new FieldValues(new FieldMap(...[...$kept, new NamedValue(new FieldHandle('title'), new TextValue($title))]), ...$head->fields->extensions);
    $next = $head->latest->next();

    return new Plan(
        new RevisionCreated($entry, $head->type, $head->variant, $next, $fields),
        new HeadMoved($entry, $head->variant, $head->revision, $next),
    );
}

it('reads the head of the shared variant and plans the next revision after the variant\'s highest number', function (): void {
    $entry = EntryId::fromString(EXAMPLE_ENTRY);
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue('A first title'))));
    $reader = new FakeVariantHeadReader()->with(new VariantHead(
        $entry,
        TypeId::fromString(EXAMPLE_TYPE),
        NodeId::fromString(EXAMPLE_HOME),
        VariantKey::shared(),
        new AggregateVersion(3),
        new RevisionNumber(2),
        new RevisionNumber(3),
        new RevisionNumber(3),
        ReleaseState::Released,
        $fields,
    ));

    $plan = retitlePlan($reader, $entry, 'A better title');
    $created = $plan?->mutations()[0] ?? null;
    $moved = $plan?->mutations()[1] ?? null;

    expect($reader->head($entry, VariantKey::shared())?->version)->toEqual(new AggregateVersion(3))
        ->and($created instanceof RevisionCreated ? [$created->revision->value, $created->fields->own->fields[0]->value] : null)->toEqual([4, new TextValue('A better title')])
        ->and($moved instanceof HeadMoved ? [$moved->from?->value, $moved->to->value] : null)->toBe([2, 4])
        ->and(retitlePlan($reader, EntryId::fromString(EXAMPLE_HOME), 'Nothing'))->toBeNull();
});

it('gives no fields for a head whose draft was written under another schema version than the type\'s', function (): void {
    $entry = EntryId::fromString(EXAMPLE_ENTRY);
    $reader = new FakeVariantHeadReader()->with(new VariantHead(
        $entry, TypeId::fromString(EXAMPLE_TYPE), NodeId::fromString(EXAMPLE_HOME), VariantKey::shared(),
        AggregateVersion::first(), new RevisionNumber(1), new RevisionNumber(1), null, ReleaseState::Unreleased, null,
    ));

    expect($reader->head($entry, VariantKey::shared())?->fields)->toBeNull()
        ->and(retitlePlan($reader, $entry, 'A title'))->toBeNull();
});
