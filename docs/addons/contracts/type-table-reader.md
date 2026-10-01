---
title: Type tables and query builders
weight: 39
description: "The TypeTableReader contract: pages of a type table with filters, keyset pagination and the explicit access predicate, the typed query builder cms:generate writes per type, the testkit's FakeTypeTableReader and the shared suite TypeTableReaderContract."
---

# Type tables and query builders

<!-- extension-point: Cbox\Cms\Contracts\TypeTables\TypeTableReader -->
<!-- extension-point: Cbox\Cms\Testkit\TypeTables\TypeTableReaderContract -->

Every type has a type table with a column per top-level field, its current state (PRD 11.6). Code reads it through the contract `Cbox\Cms\Contracts\TypeTables\TypeTableReader`, and the query builder `cms:generate` writes for each type runs on it. The kernel knows no type by name (GUARDRAILS 2.4): the reader learns the columns, and which fields are filterable and sortable, from the [type catalog](type-catalog.md). All the types on this page are `#[Experimental]`.

## The contract

The reader has one method, `page(TypeTableQuery $query, AccessContext $access): TypeTablePage`. A `TypeTableQuery` holds:

- `type`, the `TypeName`, and `variant`, the `VariantKey`; the shared variant for a type without localization.
- `filters`, a list of `ColumnFilter`: the column of a filterable field, a `FilterOperator` (`eq`, `neq`, `in`, `nin`, `lt`, `lte`, `gt`, `gte`, `null`, `not_null`) and its values, each a `TextValue`, `IntegerValue`, `DecimalValue`, `BooleanValue`, `DateValue` or `DateTimeValue`. A row matches every filter. A comparison with a null column is false, so `neq` and `nin` never match a null (PRD 8.8).
- `order`, a list of `ColumnOrder`: the column of a sortable field and a `SortDirection`. Null sorts as the greatest value, last ascending and first descending, as Postgres sorts it.
- `after`, the `TypeTableCursor` of the page before, and `limit`, 1 to `TypeTableQuery::MAX_LIMIT` (100), `DEFAULT_LIMIT` (20) when not given.

The page holds the released rows of the variant, sorted by the order and then by the entry id in the direction of the last key, so every row has one place and keyset pagination never skips or repeats a row, whatever is added. Each `TypeTableRow` has the `EntryId` and the fields as `FieldValues`: every field with a column, `NullValue` where the column is null, and no encrypted field, because the reader holds no key to its ciphertext (PRD 12.2). `next` is the cursor of the following page, the order's values of the last row and its entry id, or null when no row follows.

The fields are the ones the context may read (PRD 6.2, 12.2), by the same rule the query pipeline strips a read's fields with, `TypeDefinition::readable()`: no field classified above the context's classification access, and, for a credential issued for an agent, no field whose blueprint closes it to agents, nested group fields included. An extender whose fields are all left out is left out of the row.

The reader always adds the explicit access predicate of the `AccessContext` (PRD 5.10): a row is read when the context's actor owns it (`cms_owner_actor`), or when one of the context's regions reaches its home node. Row level security is the backstop, not the primary filter. A context without an actor and without regions reads no row. The reader refuses with `InvalidTypeTableQuery`, before it reads, a type the catalog does not have, a column that is no field's, a filter on a field its blueprint does not declare `filterable` and an order by a field it does not declare `sortable`: those have no index (PRD 8.8). It also refuses a filter on or an order by a field the context may not read (`TypeTableQuery::assertReadableBy()`), because the rows it gives back would tell the caller about the values it leaves out.

The default, `Cbox\Cms\Core\TypeTables\Adapter\PostgresTypeTableReader`, runs on the query builder inside the read transaction whose actor context the query pipeline has set, with every value a bound parameter (GUARDRAILS 6). When the context's regions reach at most 100 nodes, it lists them first and tests `cms_home_node` against the list; otherwise it left joins `nodes` and tests each row's path with `<@` against each region and its exceptions, so a row the actor owns outside its regions stays. A page costs two queries whatever the number of matching rows (GUARDRAILS 4.1). When the rows give an actor a sensitive field, it also writes the read audit (PRD 12.12) through the core's read audit, in the same read transaction, under the query name `type_tables.page` version 1 and at the read's position: one row per entry with the fields' addresses, never their values. The fake writes no audit.

## The generated query builder

`cms:generate` writes, in `QueryBuilders/<Type>` below the PHP directory (`app/Cms/Generated` in an application), for each type such as `shop:product`. The directory is not called `Queries`, because a `Queries` namespace holds the query DTOs of a module's domain:

- `ShopProductFilterField`, an enum of the owner's filterable fields, and `ShopProductSortField`, one of its sortable fields, each case valued by the field's column. A filter takes only the first and an order only the second, so filtering or sorting on a field without an index is a type error that PHPStan reports, not a query that scans the table.
- `ShopProductQuery`, a final readonly builder. `where()` takes a filter field, an operator and field values; `where<Field>()` takes the field's own PHP type, such as `whereColour(FilterOperator::In, ColourChoice::Red, ColourChoice::Blue)`; `orderBy()` takes a sort field and a direction; `after()` the cursor of the page before; `limit()` the page size. `page(AccessContext)` reads the page through the `TypeTableReader` and hydrates each row through the owner's record factory, so its `RecordPage` holds `EntryRecord`s of the owner's interface, `ShopProductRecord`. Each method returns a new builder.

Ask the container for the builder and call `page()` inside the read transaction the query pipeline opens. The builder is the owner's (PRD 11.12, point 4): it filters and sorts on the owner's fields only, and its code compiles against the owner's interfaces. A `select` field that allows several options has no order to compare, so `cms:generate` refuses it as `filterable` or `sortable` with `generate_field_not_queryable`; the blueprint schema already refuses both flags on `long_text`, `rich_text` and `group`. The golden builder of the comprehensive example is in `packages/generators/tests/Descriptor/Fixtures/Comprehensive/Generated/QueryBuilders`.

## The fake: FakeTypeTableReader

`Cbox\Cms\Testkit\TypeTables\FakeTypeTableReader` holds the released rows a test gives it with `with(TypeDefinition, TypeTableSeed ...)`, over a catalog such as the `FakeTypeCatalog`. A `TypeTableSeed` is the entry, the path of its home node, its fields and the actor that owns it, if any. The fake applies the same predicate, filters, order and refusals as the kernel's reader; text compares byte by byte, as under the C collation. This example is in the `Unit` suite:

<!-- example: examples/Unit/TypeTables/NewestNotesTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\FakeTypeTableReader;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;

// Code that lists entries of a type reads through the TypeTableReader, as the generated query
// builders do. A test hands it the testkit's fake with the type and the rows it needs.

const NOTES_DESK = '0198d2a4-5c3e-7a41-9b2f-3c8e1f6a0001';

const NOTES_ARCHIVE = '0198d2a4-5c3e-7a41-9b2f-3c8e1f6a0002';

function noteType(): TypeDefinition
{
    $field = static fn (string $handle, string $fieldType, string $column, bool $filterable, bool $sortable): FieldDefinition => new FieldDefinition(
        null,
        new FieldHandle($handle),
        $fieldType,
        ClassificationAccess::Public,
        true,
        false,
        false,
        $filterable,
        $sortable,
        new ColumnDefinition($handle, $column, false, []),
    );

    return new TypeDefinition(
        TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d30'),
        new TypeName('notes:note'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [],
        [$field('title', 'text', 'text', true, false), $field('written_on', 'date', 'date', false, true)],
    );
}

/**
 * The titles of the notes of one author, newest first, one page at a time.
 *
 * @return list<string>
 */
function newestNotes(TypeTableReader $reader, AccessContext $access, string $prefix): array
{
    $titles = [];
    $after = null;

    do {
        $page = $reader->page(new TypeTableQuery(
            new TypeName('notes:note'),
            VariantKey::shared(),
            [new ColumnFilter('title', FilterOperator::Gte, new TextValue($prefix))],
            [new ColumnOrder('written_on', SortDirection::Descending)],
            $after,
            2,
        ), $access);

        foreach ($page->rows as $row) {
            $titles[] = noteTitle($row);
        }

        $after = $page->next;
    } while ($after instanceof TypeTableCursor);

    return $titles;
}

function noteTitle(TypeTableRow $row): string
{
    $title = $row->fields->own->get(new FieldHandle('title'));

    return $title instanceof TextValue ? $title->value : '';
}

function noteReader(): FakeTypeTableReader
{
    $note = static fn (int $number, string $title, string $day, string $node): TypeTableSeed => new TypeTableSeed(
        EntryId::fromString(sprintf('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a%04d', $number)),
        new NodePath(str_replace('-', '', $node)),
        new FieldValues(new FieldMap(
            new NamedValue(new FieldHandle('title'), new TextValue($title)),
            new NamedValue(new FieldHandle('written_on'), new DateValue($day)),
        )),
    );

    return new FakeTypeTableReader(new FakeTypeCatalog(noteType()))->with(
        noteType(),
        $note(1, 'b: budget', '2026-03-01', NOTES_DESK),
        $note(2, 'b: board', '2026-03-03', NOTES_DESK),
        $note(3, 'b: briefing', '2026-03-02', NOTES_DESK),
        $note(4, 'b: archive', '2026-03-04', NOTES_ARCHIVE),
        $note(5, 'a: agenda', '2026-03-05', NOTES_DESK),
    );
}

function deskEditor(): AccessContext
{
    return new AccessContext(
        new ActorPrincipal(ActorId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a0101'), [], IssuerKind::Service, ClassificationAccess::Internal),
        [new AccessRegion(new NodePath(str_replace('-', '', NOTES_DESK)))],
        ClassificationAccess::Internal,
    );
}

it('reads the rows the editor\'s region reaches, filtered and newest first, across pages', function (): void {
    expect(newestNotes(noteReader(), deskEditor(), 'b'))->toBe(['b: board', 'b: briefing', 'b: budget']);
});

it('reads nothing for the anonymous context', function (): void {
    expect(newestNotes(noteReader(), AccessContext::anonymous(), 'b'))->toBe([]);
});

it('refuses an order by a field whose blueprint does not declare it sortable', function (): void {
    $query = new TypeTableQuery(new TypeName('notes:note'), VariantKey::shared(), [], [new ColumnOrder('title')]);

    expect(fn (): TypeTablePage => noteReader()->page($query, deskEditor()))->toThrow(InvalidTypeTableQuery::class, 'is not sortable');
});
```

## Running the shared suite

The kernel's reader and the fake run the same shared suite, the trait `Cbox\Cms\Testkit\TypeTables\TypeTableReaderContract`. Use it in a PHPUnit test class in your `tests/Contract` directory and return from `typeTableReader()` a reader over a catalog with the suite's type, after the suite's rows were written to its table. The suite's type has a field of every core field type, a group, an encrypted field and an extension field, and its rows lie in a small tree with regions, exceptions and owned rows; the cases cover the access predicate, the fields, every operator, the order with null as the greatest value, every page through each cursor, and the refusals. A replacement reader writes the rows to its own store; this example, in the `Contract` suite, runs the suite against the in-memory reader:

<!-- example: examples/Contract/TypeTables/InMemoryTypeTableReaderContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\TypeTables;

use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\FakeTypeTableReader;
use Cbox\Cms\Testkit\TypeTables\TypeTableReaderContract;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared TypeTableReader suite against a reader. typeTableReader() gets the suite's type and
 * rows: a replacement for the kernel's reader writes the rows to its own store here and returns
 * itself over a catalog with the type. This example runs it against the testkit's in-memory
 * reader, which keeps the rows it is given.
 */
final class InMemoryTypeTableReaderContractTest extends TestCase
{
    use TypeTableReaderContract;

    #[Override]
    protected function typeTableReader(TypeDefinition $type, TypeTableSeed ...$rows): TypeTableReader
    {
        return new FakeTypeTableReader(new FakeTypeCatalog($type))->with($type, ...$rows);
    }
}
```

`cboxdk/cms` runs the suite against the fake in `packages/testkit/tests/Contract/FakeTypeTableReaderContractTest.php`, and against the Postgres reader as the app role, with the actor context set and row level security on, in `packages/core/tests/Contract/PostgresTypeTableReaderContractTest.php`, and with the join to `nodes` in `PostgresJoinedTypeTableReaderContractTest.php`.
