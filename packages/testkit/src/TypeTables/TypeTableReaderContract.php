<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
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
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;
use Cbox\Cms\Contracts\TypeTables\CursorKey;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for TypeTableReader (GUARDRAILS 2.3 and 9). The FakeTypeTableReader and
 * the kernel's reader on Postgres run the same cases, so a test that reads through the fake sees
 * what the kernel reads.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return from
 * typeTableReader() a reader over a catalog with the type, after the rows were written to its
 * table, as released rows of the shared variant:
 *
 *     final class FakeTypeTableReaderContractTest extends TestCase
 *     {
 *         use TypeTableReaderContract;
 *
 *         protected function typeTableReader(TypeDefinition $type, TypeTableSeed ...$rows): TypeTableReader
 *         {
 *             return new FakeTypeTableReader(new FakeTypeCatalog($type))->with($type, ...$rows);
 *         }
 *     }
 *
 * The suite's type, suiteType(), has a field of every core field type, an encrypted field and an
 * extension field. Its rows lie in a small tree, where ALICE's regions reach NORTH, SOUTH without
 * CITY, and PARK inside CITY, and she owns one row in WEST; BOB has no region and owns another row
 * in WEST. The cases hold the reader to the access predicate, the fields it gives, every filter
 * operator, the order with null as the greatest value, keyset pagination through every page, and
 * the refusals of PRD 8.8. Labels are lowercase ASCII, so text compares the same under every
 * collation.
 */
#[Experimental]
trait TypeTableReaderContract
{
    /** The suite's type, `suite:item`. */
    public const string SUITE_TYPE = 'suite:item';

    public const string ROOT = '0192a0c0-0000-7000-8000-000000000101';

    public const string NORTH = '0192a0c0-0000-7000-8000-000000000102';

    public const string SOUTH = '0192a0c0-0000-7000-8000-000000000103';

    public const string CITY = '0192a0c0-0000-7000-8000-000000000104';

    public const string PARK = '0192a0c0-0000-7000-8000-000000000105';

    public const string WEST = '0192a0c0-0000-7000-8000-000000000106';

    public const string ALICE = '0192a0c0-0000-7000-8000-000000000201';

    public const string BOB = '0192a0c0-0000-7000-8000-000000000202';

    /**
     * The reader under test, over a catalog that holds the type, after the rows were written to
     * the type's table as released rows of the shared variant.
     */
    abstract protected function typeTableReader(TypeDefinition $type, TypeTableSeed ...$rows): TypeTableReader;

    /**
     * The suite's type: a field of every core field type, a group with nested fields, an
     * encrypted field and an extension field of the namespace acme.
     */
    public static function suiteType(): TypeDefinition
    {
        $field = static fn (string $handle, string $fieldType, string $column, bool $notNull = false, bool $filterable = false, bool $sortable = false, ?string $namespace = null): FieldDefinition => new FieldDefinition(
            namespace: $namespace === null ? null : new FieldNamespace($namespace),
            handle: new FieldHandle($handle),
            fieldType: $fieldType,
            classification: ClassificationAccess::Public,
            agents: true,
            encrypted: false,
            required: $notNull,
            filterable: $filterable,
            sortable: $sortable,
            column: new ColumnDefinition($namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle, $column, $notNull, []),
        );
        $nested = static fn (string $handle, string $fieldType): FieldDefinition => new FieldDefinition(null, new FieldHandle($handle), $fieldType, ClassificationAccess::Public, true, false, false, false, false, null);

        return new TypeDefinition(
            TypeId::fromString('0192a0c0-0000-7000-8000-00000000f001'),
            new TypeName(self::SUITE_TYPE),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
            [new ExtensionVersion(new FieldNamespace('acme'), 1)],
            [
                $field('label', 'text', 'text', notNull: true, filterable: true, sortable: true),
                $field('rank', 'integer', 'bigint', filterable: true, sortable: true),
                $field('price', 'decimal', 'numeric(10, 2)', filterable: true),
                $field('day', 'date', 'date', sortable: true),
                $field('at', 'datetime', 'timestamptz', filterable: true),
                $field('flag', 'boolean', 'boolean', filterable: true),
                $field('tags', 'select', 'text[]'),
                $field('body', 'rich_text', 'jsonb'),
                new FieldDefinition(null, new FieldHandle('notes'), 'group', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('notes', 'jsonb', false, []), [
                    $nested('note', 'text'),
                    $nested('amount', 'decimal'),
                    $nested('seen_at', 'datetime'),
                ]),
                new FieldDefinition(null, new FieldHandle('secret'), 'text', ClassificationAccess::Confidential, false, true, false, false, false, new ColumnDefinition('secret', 'bytea', false, [])),
                $field('code', 'text', 'text', filterable: true, sortable: true, namespace: 'acme'),
            ],
        );
    }

    /**
     * The path of a node of the suite's tree, by the ids from the root down.
     */
    public static function suitePath(string ...$nodes): NodePath
    {
        return new NodePath(implode('.', array_map(static fn (string $node): string => str_replace('-', '', $node), $nodes)));
    }

    #[Test]
    public function it_reads_the_rows_the_regions_reach_and_the_rows_the_actor_owns_sorted_by_entry(): void
    {
        $page = $this->suiteReader()->page(self::suiteQuery(), self::suiteAlice());

        Assert::assertSame(['e01', 'e02', 'e03', 'e06', 'e07', 'e09', 'e10'], self::suiteNames($page->rows));
        Assert::assertNull($page->next);
    }

    #[Test]
    public function an_actor_without_regions_reads_only_the_rows_it_owns(): void
    {
        Assert::assertSame(['e05'], self::suiteNames($this->suiteReader()->page(self::suiteQuery(), self::suiteBob())->rows));
    }

    #[Test]
    public function the_anonymous_context_reads_no_row(): void
    {
        $page = $this->suiteReader()->page(self::suiteQuery(), AccessContext::anonymous());

        Assert::assertSame([], $page->rows);
        Assert::assertNull($page->next);
    }

    #[Test]
    public function another_variant_than_the_rows_reads_no_row(): void
    {
        $query = new TypeTableQuery(new TypeName(self::SUITE_TYPE), VariantKey::of(new Locale('da')));

        Assert::assertSame([], $this->suiteReader()->page($query, self::suiteAlice())->rows);
    }

    #[Test]
    public function it_gives_every_field_with_a_column_but_the_encrypted_one(): void
    {
        $rows = $this->suiteReader()->page(self::suiteQuery(filters: [new ColumnFilter('label', FilterOperator::In, new TextValue('delta'), new TextValue('alpha'), new TextValue('india'))]), self::suiteAlice())->rows;

        Assert::assertSame(['e01', 'e02', 'e09'], self::suiteNames($rows));

        foreach ($rows as $row) {
            $seed = array_find(self::suiteRows(), static fn (TypeTableSeed $seed): bool => $seed->entry->equals($row->entry));
            Assert::assertInstanceOf(TypeTableSeed::class, $seed);
            $expected = self::suiteRead($seed->fields);
            Assert::assertTrue($expected->equals($row->fields), sprintf('The fields of %s differ from the fields the row holds.', self::suiteName($row)));
        }
    }

    /**
     * The filter cases: each query of ALICE's rows with the filters gives the rows, by entry.
     *
     * @return list<ReaderSuiteCase>
     */
    public static function suiteFilters(): array
    {
        return [
            new ReaderSuiteCase('eq', [new ColumnFilter('label', FilterOperator::Eq, new TextValue('delta'))], [], ['e01']),
            new ReaderSuiteCase('neq never matches a null', [new ColumnFilter('rank', FilterOperator::Neq, new IntegerValue(3))], [], ['e03', 'e09', 'e10']),
            new ReaderSuiteCase('in', [new ColumnFilter('rank', FilterOperator::In, new IntegerValue(1), new IntegerValue(7))], [], ['e03', 'e09']),
            new ReaderSuiteCase('nin never matches a null', [new ColumnFilter('rank', FilterOperator::NotIn, new IntegerValue(1), new IntegerValue(7))], [], ['e01', 'e06', 'e10']),
            new ReaderSuiteCase('gt on a decimal', [new ColumnFilter('price', FilterOperator::Gt, new DecimalValue('10.5'))], [], ['e09']),
            new ReaderSuiteCase('gte on a decimal', [new ColumnFilter('price', FilterOperator::Gte, new DecimalValue('10.50'))], [], ['e01', 'e06', 'e09']),
            new ReaderSuiteCase('lt on a decimal', [new ColumnFilter('price', FilterOperator::Lt, new DecimalValue('2'))], [], ['e03']),
            new ReaderSuiteCase('lte on a decimal', [new ColumnFilter('price', FilterOperator::Lte, new DecimalValue('2'))], [], ['e02', 'e03']),
            new ReaderSuiteCase('lt on a date-time', [new ColumnFilter('at', FilterOperator::Lt, new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00.5Z')))], [], ['e01', 'e09']),
            new ReaderSuiteCase('lte on a date-time', [new ColumnFilter('at', FilterOperator::Lte, new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00.5Z')))], [], ['e01', 'e03', 'e09']),
            new ReaderSuiteCase('eq on a boolean', [new ColumnFilter('flag', FilterOperator::Eq, new BooleanValue(false))], [], ['e02', 'e09']),
            new ReaderSuiteCase('null', [new ColumnFilter('flag', FilterOperator::IsNull)], [], ['e06', 'e10']),
            new ReaderSuiteCase('not null on an extension field', [new ColumnFilter('ext__acme__code', FilterOperator::IsNotNull)], [], ['e01', 'e02', 'e06', 'e07', 'e09']),
            new ReaderSuiteCase('every filter must match', [new ColumnFilter('flag', FilterOperator::Eq, new BooleanValue(true)), new ColumnFilter('rank', FilterOperator::Gte, new IntegerValue(1))], [], ['e01', 'e03']),
        ];
    }

    /**
     * The order cases: each query of ALICE's rows in the order gives the rows in that order.
     *
     * @return list<ReaderSuiteCase>
     */
    public static function suiteOrders(): array
    {
        return [
            new ReaderSuiteCase('no order: by entry', [], [], ['e01', 'e02', 'e03', 'e06', 'e07', 'e09', 'e10']),
            new ReaderSuiteCase('ascending, nulls last', [], [new ColumnOrder('rank')], ['e03', 'e01', 'e06', 'e10', 'e09', 'e02', 'e07']),
            new ReaderSuiteCase('descending, nulls first and entries descending', [], [new ColumnOrder('rank', SortDirection::Descending)], ['e07', 'e02', 'e09', 'e10', 'e06', 'e01', 'e03']),
            new ReaderSuiteCase('descending on a date', [], [new ColumnOrder('day', SortDirection::Descending)], ['e10', 'e09', 'e02', 'e06', 'e01', 'e07', 'e03']),
            new ReaderSuiteCase('on text', [], [new ColumnOrder('label')], ['e02', 'e03', 'e01', 'e06', 'e07', 'e09', 'e10']),
            new ReaderSuiteCase('on an extension field, then descending', [], [new ColumnOrder('ext__acme__code'), new ColumnOrder('rank', SortDirection::Descending)], ['e06', 'e01', 'e02', 'e09', 'e07', 'e10', 'e03']),
        ];
    }

    #[Test]
    public function it_filters_on_filterable_fields(): void
    {
        $reader = $this->suiteReader();

        foreach (self::suiteFilters() as $case) {
            Assert::assertSame($case->expected, self::suiteNames($reader->page(self::suiteQuery(filters: $case->filters), self::suiteAlice())->rows), $case->name);
        }
    }

    #[Test]
    public function it_sorts_with_null_as_the_greatest_value_and_the_entry_last(): void
    {
        $reader = $this->suiteReader();

        foreach (self::suiteOrders() as $case) {
            Assert::assertSame($case->expected, self::suiteNames($reader->page(self::suiteQuery(order: $case->order), self::suiteAlice())->rows), $case->name);
        }
    }

    #[Test]
    public function the_pages_after_each_cursor_hold_every_row_once_in_order(): void
    {
        $reader = $this->suiteReader();

        foreach (self::suiteOrders() as $case) {
            foreach ([1, 2, 3] as $limit) {
                $names = [];
                $after = null;
                $pages = 0;

                do {
                    $page = $reader->page(self::suiteQuery(order: $case->order, after: $after, limit: $limit), self::suiteAlice());
                    $names = [...$names, ...self::suiteNames($page->rows)];
                    $after = $page->next;
                    $pages++;
                } while ($after instanceof TypeTableCursor && $pages <= count($case->expected));

                Assert::assertSame($case->expected, $names, sprintf('%s, %d a page', $case->name, $limit));
                Assert::assertSame((int) ceil(count($case->expected) / $limit), $pages, sprintf('%s, %d a page', $case->name, $limit));
            }
        }
    }

    #[Test]
    public function the_cursor_holds_the_order_columns_of_the_last_row(): void
    {
        $reader = $this->suiteReader();
        $page = $reader->page(self::suiteQuery(order: [new ColumnOrder('rank', SortDirection::Descending), new ColumnOrder('label')], limit: 3), self::suiteAlice());
        $next = $page->next;

        Assert::assertInstanceOf(TypeTableCursor::class, $next);
        Assert::assertSame(self::suiteEntries()[8], $next->entry->toString());
        Assert::assertSame(['rank', 'label'], array_map(static fn (CursorKey $key): string => $key->column, $next->keys));
        Assert::assertTrue($next->keys[0]->value->equals(new IntegerValue(7)));
        Assert::assertTrue($next->keys[1]->value->equals(new TextValue('india')));

        $nulls = $reader->page(self::suiteQuery(order: [new ColumnOrder('rank', SortDirection::Descending)], limit: 1), self::suiteAlice())->next;

        Assert::assertInstanceOf(TypeTableCursor::class, $nulls);
        Assert::assertInstanceOf(NullValue::class, $nulls->keys[0]->value);
    }

    #[Test]
    public function it_filters_sorts_and_pages_together(): void
    {
        $query = static fn (?TypeTableCursor $after): TypeTableQuery => self::suiteQuery(
            filters: [new ColumnFilter('rank', FilterOperator::IsNotNull)],
            order: [new ColumnOrder('rank', SortDirection::Descending)],
            after: $after,
            limit: 2,
        );
        $reader = $this->suiteReader();
        $first = $reader->page($query(null), self::suiteAlice());
        $second = $reader->page($query($first->next), self::suiteAlice());
        $third = $reader->page($query($second->next), self::suiteAlice());

        Assert::assertSame(['e09', 'e10'], self::suiteNames($first->rows));
        Assert::assertSame(['e06', 'e01'], self::suiteNames($second->rows));
        Assert::assertSame(['e03'], self::suiteNames($third->rows));
        Assert::assertNull($third->next);
    }

    #[Test]
    public function it_refuses_a_type_the_catalog_does_not_have(): void
    {
        $this->assertSuiteRefused(new TypeTableQuery(new TypeName('suite:missing'), VariantKey::shared()), 'The installation has no type suite:missing.');
    }

    #[Test]
    public function it_refuses_a_filter_on_a_field_that_is_not_filterable(): void
    {
        $this->assertSuiteRefused(self::suiteQuery(filters: [new ColumnFilter('day', FilterOperator::IsNull)]), 'is not filterable');
    }

    #[Test]
    public function it_refuses_an_order_by_a_field_that_is_not_sortable(): void
    {
        $this->assertSuiteRefused(self::suiteQuery(order: [new ColumnOrder('price')]), 'is not sortable');
    }

    #[Test]
    public function it_refuses_a_column_that_is_no_fields(): void
    {
        $this->assertSuiteRefused(self::suiteQuery(filters: [new ColumnFilter('cms_home_node', FilterOperator::IsNull)]), 'has no field with the column cms_home_node');
    }

    private function assertSuiteRefused(TypeTableQuery $query, string $message): void
    {
        try {
            $this->suiteReader()->page($query, self::suiteAlice());
        } catch (InvalidTypeTableQuery $exception) {
            Assert::assertStringContainsString($message, $exception->getMessage());

            return;
        }

        Assert::fail('The reader read a query it must refuse.');
    }

    private function suiteReader(): TypeTableReader
    {
        return $this->typeTableReader(self::suiteType(), ...self::suiteRows());
    }

    /**
     * @param  list<ColumnFilter>  $filters
     * @param  list<ColumnOrder>  $order
     */
    private static function suiteQuery(array $filters = [], array $order = [], ?TypeTableCursor $after = null, int $limit = TypeTableQuery::MAX_LIMIT): TypeTableQuery
    {
        return new TypeTableQuery(new TypeName(self::SUITE_TYPE), VariantKey::shared(), $filters, $order, $after, $limit);
    }

    private static function suiteAlice(): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal(ActorId::fromString(self::ALICE), [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [
                new AccessRegion(self::suitePath(self::ROOT, self::NORTH)),
                new AccessRegion(self::suitePath(self::ROOT, self::SOUTH), [self::suitePath(self::ROOT, self::SOUTH, self::CITY)]),
                new AccessRegion(self::suitePath(self::ROOT, self::SOUTH, self::CITY, self::PARK)),
            ],
            ClassificationAccess::Sensitive,
        );
    }

    private static function suiteBob(): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal(ActorId::fromString(self::BOB), [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Sensitive,
        );
    }

    /**
     * The entry ids of the suite's rows, e01 to e10.
     *
     * @return list<string>
     */
    private static function suiteEntries(): array
    {
        return array_map(static fn (int $number): string => sprintf('0192a0c0-0000-7000-8000-0000000003%02d', $number), range(1, 10));
    }

    /**
     * The suite's rows, e01 to e10: e04 in CITY and e08 at the ROOT, which ALICE's regions do not
     * reach, e05 in WEST, which BOB owns, and e10 in WEST, which ALICE owns.
     *
     * @return list<TypeTableSeed>
     */
    private static function suiteRows(): array
    {
        $text = static fn (?string $value): FieldValue => $value === null ? new NullValue : new TextValue($value);
        $integer = static fn (?int $value): FieldValue => $value === null ? new NullValue : new IntegerValue($value);
        $decimal = static fn (?string $value): FieldValue => $value === null ? new NullValue : new DecimalValue($value);
        $day = static fn (?string $value): FieldValue => $value === null ? new NullValue : new DateValue($value);
        $at = static fn (?string $value): FieldValue => $value === null ? new NullValue : new DateTimeValue(new DateTimeImmutable($value));
        $flag = static fn (?bool $value): FieldValue => $value === null ? new NullValue : new BooleanValue($value);
        $row = static fn (string $label, ?int $rank, ?string $price, ?string $date, ?string $instant, ?bool $bool, ?string $code, NamedValue ...$more): FieldValues => new FieldValues(
            new FieldMap(
                new NamedValue(new FieldHandle('label'), new TextValue($label)),
                new NamedValue(new FieldHandle('rank'), $integer($rank)),
                new NamedValue(new FieldHandle('price'), $decimal($price)),
                new NamedValue(new FieldHandle('day'), $day($date)),
                new NamedValue(new FieldHandle('at'), $at($instant)),
                new NamedValue(new FieldHandle('flag'), $flag($bool)),
                ...$more,
            ),
            new ExtensionFields(new FieldNamespace('acme'), new FieldMap(new NamedValue(new FieldHandle('code'), $text($code)))),
        );
        $note = static fn (string $note, string $amount): GroupValue => new GroupValue(new FieldMap(
            new NamedValue(new FieldHandle('note'), new TextValue($note)),
            new NamedValue(new FieldHandle('amount'), new DecimalValue($amount)),
            new NamedValue(new FieldHandle('seen_at'), new DateTimeValue(new DateTimeImmutable('2026-03-10T08:30:00.25Z'))),
        ));
        $rich = [
            new NamedValue(new FieldHandle('tags'), new ListValue(new TextValue('red'), new TextValue('blue "sky"'))),
            new NamedValue(new FieldHandle('notes'), $note('checked', '1.5')),
            new NamedValue(new FieldHandle('body'), new ListValue(new MapValue(
                new MapEntry('_type', new TextValue('block')),
                new MapEntry('style', new TextValue('normal')),
                new MapEntry('children', new ListValue(new MapValue(new MapEntry('_type', new TextValue('span')), new MapEntry('text', new TextValue('Hello'))))),
            ))),
            new NamedValue(new FieldHandle('secret'), new NullValue),
        ];
        [$e01, $e02, $e03, $e04, $e05, $e06, $e07, $e08, $e09, $e10] = array_map(EntryId::fromString(...), self::suiteEntries());
        $north = self::suitePath(self::ROOT, self::NORTH);
        $south = self::suitePath(self::ROOT, self::SOUTH);
        $city = self::suitePath(self::ROOT, self::SOUTH, self::CITY);
        $park = self::suitePath(self::ROOT, self::SOUTH, self::CITY, self::PARK);
        $west = self::suitePath(self::ROOT, self::WEST);

        return [
            new TypeTableSeed($e01, $north, $row('delta', 3, '10.5', '2026-01-03', '2026-03-10T10:00:00Z', true, 'x', ...$rich)),
            new TypeTableSeed($e02, $south, $row('alpha', null, '2', null, null, false, 'y')),
            new TypeTableSeed($e03, $park, $row('charlie', 1, '-4.25', '2026-01-01', '2026-03-10T12:00:00.5Z', true, null)),
            new TypeTableSeed($e04, $city, $row('bravo', 2, '3', '2026-01-04', null, true, 'x')),
            new TypeTableSeed($e05, $west, $row('echo', 5, null, null, null, false, null), ActorId::fromString(self::BOB)),
            new TypeTableSeed($e06, $north, $row('foxtrot', 3, '10.50', '2026-01-03', '2026-03-11T00:00:00Z', null, 'x')),
            new TypeTableSeed($e07, $south, $row('golf', null, null, '2026-01-02', null, true, 'z')),
            new TypeTableSeed($e08, self::suitePath(self::ROOT), $row('hotel', 8, '8', null, null, true, 'x')),
            new TypeTableSeed($e09, $park, $row('india', 7, '100', null, '2026-03-09T23:59:59Z', false, 'y', new NamedValue(new FieldHandle('notes'), new ListValue($note('first', '0'), $note('second', '-2.75'))))),
            new TypeTableSeed($e10, $west, $row('juliet', 4, null, null, null, null, null), ActorId::fromString(self::ALICE)),
        ];
    }

    /**
     * The fields a reader gives for a row's fields: NullValue for a field with a column the row
     * holds no value for, and no encrypted field.
     */
    private static function suiteRead(FieldValues $fields): FieldValues
    {
        $own = [];

        foreach (['at', 'body', 'day', 'flag', 'label', 'notes', 'price', 'rank', 'tags'] as $handle) {
            $own[] = new NamedValue(new FieldHandle($handle), $fields->own->get(new FieldHandle($handle)) ?? new NullValue);
        }

        return new FieldValues(new FieldMap(...$own), ...$fields->extensions);
    }

    /**
     * The names of the rows' entries, e01 to e10.
     *
     * @param  list<TypeTableRow>  $rows
     * @return list<string>
     */
    private static function suiteNames(array $rows): array
    {
        return array_map(self::suiteName(...), $rows);
    }

    private static function suiteName(TypeTableRow $row): string
    {
        $index = array_search($row->entry->toString(), self::suiteEntries(), true);

        return $index === false ? $row->entry->toString() : sprintf('e%02d', $index + 1);
    }
}
