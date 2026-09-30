<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Codecs\FakeRecordCodecs;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;

// Code that serves entries, such as a feed, never names a type: it hands each entry a read
// returned to RecordCodecs and gets the JSON of its type's record DTO, without a field above the
// caller's classification access. A test gives the fake the types it needs.

/**
 * The body of a feed item: the record of an entry as the public sees it.
 */
function feedItem(RecordCodecs $records, ReadContent $entry): string
{
    return $records->encode($entry, ClassificationAccess::Public);
}

function recordNoteType(): TypeDefinition
{
    $text = static fn (string $handle, ClassificationAccess $classification): FieldDefinition => new FieldDefinition(
        namespace: null,
        handle: new FieldHandle($handle),
        fieldType: 'text',
        classification: $classification,
        agents: true,
        encrypted: false,
        required: false,
        filterable: false,
        sortable: false,
        column: new ColumnDefinition($handle, 'text', false, []),
    );

    return new TypeDefinition(
        TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d30'),
        new TypeName('app:note'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
        [],
        [$text('title', ClassificationAccess::Public), $text('source', ClassificationAccess::Internal)],
    );
}

function recordNoteEntry(TypeId $type): ReadContent
{
    return new ReadContent(
        EntryId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d31'),
        NodeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d32'),
        $type,
        new FieldValues(new FieldMap(
            new NamedValue(new FieldHandle('source'), new TextValue('The harbour office')),
            new NamedValue(new FieldHandle('title'), new TextValue('The harbour opens')),
        )),
    );
}

it('writes an entry as its record, without the fields above public', function (): void {
    $records = new FakeRecordCodecs(new FakeTypeCatalog(recordNoteType()));

    expect(feedItem($records, recordNoteEntry(recordNoteType()->id)))
        ->toBe('{"cms_id":"0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d31","title":"The harbour opens"}');
});

it('refuses an entry of a type the installation does not have', function (): void {
    $records = new FakeRecordCodecs(new FakeTypeCatalog(recordNoteType()));

    expect(static fn (): string => feedItem($records, recordNoteEntry(TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d39'))))
        ->toThrow(InvalidRecordDocument::class);
});
