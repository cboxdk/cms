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
