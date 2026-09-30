<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\TypeTables;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
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
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\FakeTypeTableReader;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;

/*
 * The fake's own rules beyond the shared suite: it takes rows only for a type of its catalog, adds
 * rows to the ones it holds without changing itself, and refuses to compare values of two kinds.
 */

function fakeType(string $name): TypeDefinition
{
    return new TypeDefinition(
        TypeId::fromString($name === 'shop:item' ? '0192a0c0-0000-7000-8000-00000000f011' : '0192a0c0-0000-7000-8000-00000000f012'),
        new TypeName($name),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [],
        [new FieldDefinition(null, new FieldHandle('rank'), 'integer', ClassificationAccess::Public, true, false, false, true, true, new ColumnDefinition('rank', 'bigint', false, []))],
    );
}

function fakeSeed(int $number): TypeTableSeed
{
    return new TypeTableSeed(
        EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', $number)),
        new NodePath('root'),
        new FieldValues(new FieldMap(new NamedValue(new FieldHandle('rank'), new IntegerValue($number)))),
    );
}

function fakeAccess(): AccessContext
{
    return new AccessContext(
        new ActorPrincipal(ActorId::fromString('0192a0c0-0000-7000-8000-000000000201'), [], IssuerKind::Service, ClassificationAccess::Internal),
        [new AccessRegion(new NodePath('root'))],
        ClassificationAccess::Internal,
    );
}

/**
 * @return list<int>
 */
function fakeRanks(FakeTypeTableReader $reader, string $type = 'shop:item'): array
{
    return array_map(
        static fn (TypeTableRow $row): int => (int) substr($row->entry->toString(), -12),
        $reader->page(new TypeTableQuery(new TypeName($type), VariantKey::shared()), fakeAccess())->rows,
    );
}

it('refuses rows of a type its catalog does not have', function (): void {
    expect(fn (): FakeTypeTableReader => new FakeTypeTableReader(new FakeTypeCatalog(fakeType('shop:item')))->with(fakeType('shop:other'), fakeSeed(1)))
        ->toThrow(InvalidTypeTableQuery::class, 'The installation has no type shop:other.');
});

it('adds rows to a new reader and keeps the rows of each type apart', function (): void {
    $empty = new FakeTypeTableReader(new FakeTypeCatalog(fakeType('shop:item'), fakeType('shop:other')));
    $one = $empty->with(fakeType('shop:item'), fakeSeed(1));
    $two = $one->with(fakeType('shop:item'), fakeSeed(2))->with(fakeType('shop:other'), fakeSeed(3));

    expect(fakeRanks($empty))->toBe([])
        ->and(fakeRanks($one))->toBe([1])
        ->and(fakeRanks($two))->toBe([1, 2])
        ->and(fakeRanks($two, 'shop:other'))->toBe([3]);
});

it('refuses to compare a value of another kind than the column holds', function (): void {
    $reader = new FakeTypeTableReader(new FakeTypeCatalog(fakeType('shop:item')))->with(fakeType('shop:item'), fakeSeed(1));
    $query = new TypeTableQuery(new TypeName('shop:item'), VariantKey::shared(), [new ColumnFilter('rank', FilterOperator::Eq, new TextValue('1'))]);

    expect(fn (): TypeTablePage => $reader->page($query, fakeAccess()))->toThrow(InvalidTypeTableQuery::class, 'but the one for rank is a '.TextValue::class);
});
