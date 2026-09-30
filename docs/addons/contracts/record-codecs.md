---
title: Record codecs
weight: 39
description: "The RecordCodecs contract: how an entry a read returned leaves the server as the record DTO of its type, through the codec cms:generate wrote, without a field above the caller's classification access; the generated GeneratedRecordCodecs, the testkit's FakeRecordCodecs and the shared suite RecordCodecsContract."
---

# Record codecs

<!-- extension-point: Cbox\Cms\Contracts\Codecs\RecordCodecs -->
<!-- extension-point: Cbox\Cms\Testkit\Codecs\RecordCodecsContract -->

Every projection hands out DTOs, never rows (PRD 8.9). A read returns an entry in the kernel's generic form, a `Cbox\Cms\Contracts\Results\ReadContent` with the entry's id, its node, its type and its `FieldValues`. The kernel knows no type by name (GUARDRAILS 2.4), so it cannot name the record DTO of the entry's type. It asks the contract `Cbox\Cms\Contracts\Codecs\RecordCodecs` instead, which the code `cms:generate` writes implements. The delivery API's `GET /v1/resolve` answers with it; a feed, a search document or a webhook does the same.

## The contract

| Method | Returns |
|---|---|
| `types(): list<TypeId>` | the id of every type with a codec, sorted, each once |
| `encode(ReadContent $content, ClassificationAccess $access): string` | the JSON of the entry's record DTO as a caller with the access may see it |

`encode()` builds the record DTO of the entry's type in contract version `RecordCodecs::VERSION`, 1, from the entry's id and its field values, and writes it through the type's generated codec: keys sorted, no whitespace, the id under `cms_id`, each of the owner's fields under its handle and the extension fields under `ext.<namespace>`. A field classified above the access is never written (invariant 10). A field the content does not hold, such as one a read stripped, is left out. A type the installation does not have, and field values the type's contract refuses, such as a text longer than its rule allows, throw `InvalidRecordDocument`: that is generated code that does not match the stored rows, a fault of the installation, not of the caller.

Like the `TypeCatalog`, the record codecs are fixed for the life of the process and read nothing. There is one codec for every type of the catalog and none for another.

## The generated record codecs

`cms:generate` writes `GeneratedRecordCodecs` next to `GeneratedTypeCatalog` in the PHP directory, and `GeneratedTypesServiceProvider` binds `RecordCodecs` to it. Its `encode()` hands the entry, the catalog and the type's codec, such as `ShopProductCodecV1`, to the core's `Cbox\Cms\Core\Codecs\Boundary\RecordDocument`, which builds the record's JSON document from the field values in the form the type table's groups use, reads it with the codec at the highest access, so every rule of every value is checked, and writes the DTO it read at the caller's access. The golden file of the comprehensive example is `packages/generators/tests/Descriptor/Fixtures/Comprehensive/Generated/GeneratedRecordCodecs.php`.

## The fake: FakeRecordCodecs

`Cbox\Cms\Testkit\Codecs\FakeRecordCodecs` writes a record for every type of the catalog a test gives it, in the same form, and leaves out every field above the access, with its group when it is one. It refuses a type the catalog does not have and a value that does not fit its field, as the generated class does, but it does not check a blueprint's rules, such as a text's length. This example is in the `Unit` suite:

<!-- example: examples/Unit/Codecs/RecordCodecsTest.php -->
```php
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
```

## The shared suite: RecordCodecsContract

`Cbox\Cms\Testkit\Codecs\RecordCodecsContract` holds an implementation to the contract. A PHPUnit class in the package's `tests/Contract` directory uses the trait and returns the codecs, the catalog of the same types, and at least one entry of those types with field values their records accept. The suite checks that the codecs cover exactly the catalog's types, that `encode()` writes an object with the entry's id, sorted keys and the same bytes every time, that for every classification access no field above it is written and every field it allows and the entry holds is, and that an unknown type and a value that does not fit its field are refused. The example runs it against the workbench's generated classes:

<!-- example: examples/Contract/Codecs/GeneratedRecordCodecsContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Codecs;

use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Codecs\RecordCodecsContract;
use DateTimeImmutable;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedRecordCodecs;
use Workbench\App\Cms\Generated\GeneratedTypeCatalog;

/**
 * The shared RecordCodecs suite against the record codecs cms:generate writes, next to the catalog
 * of the same schema and a fixture measurement with its internal station. In an application the
 * classes are in App\Cms\Generated; here they are the workbench's. Both have a constructor without
 * arguments, so the suite needs no application.
 */
final class GeneratedRecordCodecsContractTest extends TestCase
{
    use RecordCodecsContract;

    #[Override]
    protected function codecs(): RecordCodecs
    {
        return new GeneratedRecordCodecs;
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new GeneratedTypeCatalog;
    }

    #[Override]
    protected function contents(): array
    {
        $type = $this->catalog()->named(new TypeName('app:fixture_measurement')) ?? throw new LogicException('The workbench has no fixture measurement.');

        return [new ReadContent(
            EntryId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
            NodeId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'),
            $type->id,
            new FieldValues(new FieldMap(
                new NamedValue(new FieldHandle('fixture_measured_at'), new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00Z'))),
                new NamedValue(new FieldHandle('fixture_reading'), new DecimalValue('21.500')),
                new NamedValue(new FieldHandle('fixture_scale'), new TextValue('fixture_celsius')),
                new NamedValue(new FieldHandle('fixture_station'), new TextValue('DK-042')),
            )),
        )];
    }
}
```

The testkit runs the suite against the fake, and the generators package against the workbench's generated classes from the container (`packages/generators/tests/Contract/WorkbenchRecordCodecsContractTest.php`).
