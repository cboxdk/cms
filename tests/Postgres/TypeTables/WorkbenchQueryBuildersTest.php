<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\TypeTables;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\EntryRecord;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\RecordPage;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Tests\TypeTables\PostgresTypeTables;
use Cbox\Cms\Core\TypeTables\Adapter\PostgresTypeTableReader;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureArticle\AppFixtureArticleQuery;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureArticle\AppFixtureArticleSortField;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureMeasurement\AppFixtureMeasurementQuery;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureMeasurement\AppFixtureMeasurementSortField;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticle;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticleExt;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticleFixtureaddonFields;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticleRecord;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\AppFixtureMeasurement;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\AppFixtureMeasurementRecord;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\AppFixtureMeasurementRecordFactory;
use Workbench\App\Cms\Generated\Records\AppFixtureMeasurement\FixtureScaleChoice;

/*
 * The workbench's generated query builders on the real type tables (PRD 5.10, 8.8, 11.12,
 * GUARDRAILS 4.1): as the app role, with the actor context set in the read transaction, they
 * filter, sort and paginate with a keyset through every page, return only the rows the actor's two
 * regions reach, hydrate each row through the record factory into the owner's interface, and a page
 * of 20 costs the same queries whether 20 or 200 rows match.
 */

const ROOT = '0192a0c0-0000-7000-8000-000000000a01';

const NORTH = '0192a0c0-0000-7000-8000-000000000a02';

const SOUTH = '0192a0c0-0000-7000-8000-000000000a03';

const WEST = '0192a0c0-0000-7000-8000-000000000a04';

const EDITOR = '0192a0c0-0000-7000-8000-000000000a05';

function node(string ...$ids): NodePath
{
    return new NodePath(implode('.', array_map(static fn (string $id): string => str_replace('-', '', $id), $ids)));
}

function editor(): AccessContext
{
    return new AccessContext(
        new ActorPrincipal(ActorId::fromString(EDITOR), [], IssuerKind::Service, ClassificationAccess::Internal),
        [new AccessRegion(node(ROOT, NORTH)), new AccessRegion(node(ROOT, SOUTH))],
        ClassificationAccess::Internal,
    );
}

function workbenchType(string $name): TypeDefinition
{
    $type = app(TypeCatalog::class)->named(new TypeName($name));
    expect($type)->toBeInstanceOf(TypeDefinition::class);
    assert($type instanceof TypeDefinition);

    return $type;
}

function entryId(int $number): EntryId
{
    return EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', $number));
}

/**
 * The reading of a measurement: half its number, with three decimals.
 *
 * @return numeric-string
 */
function reading(int $number): string
{
    $reading = sprintf('%d.%03d', intdiv($number, 2), ($number % 2) * 500);

    return is_numeric($reading) ? $reading : throw new LogicException('Not a number: '.$reading);
}

/**
 * Measurements from $first to $last in the region at $home: every second one in Celsius, taken a
 * minute apart from 2026-03-10, so their order by fixture_measured_at is their number's.
 *
 * @return list<TypeTableSeed>
 */
function measurements(int $first, int $last, NodePath $home): array
{
    return array_map(static fn (int $number): TypeTableSeed => new TypeTableSeed(
        entryId($number),
        $home,
        new AppFixtureMeasurement(
            fixtureAlerts: null,
            fixtureCalibrated: $number % 3 === 0,
            fixtureCalibratedOn: null,
            fixtureMeasuredAt: new DateTimeImmutable('2026-03-10T00:00:00Z')->modify(sprintf('+%d minutes', $number)),
            fixtureNote: null,
            fixtureReading: reading($number),
            fixtureRemark: null,
            fixtureSamples: $number,
            fixtureScale: $number % 2 === 0 ? FixtureScaleChoice::FixtureCelsius : FixtureScaleChoice::FixtureKelvin,
            fixtureSensor: null,
            fixtureSeries: null,
            fixtureStation: 'st'.($number % 5),
        )->toFieldValues(),
    ), range($first, $last));
}

/**
 * The page in a read transaction of the app role with the editor's context set, as the query
 * pipeline reads it.
 *
 * @template T of object
 *
 * @param  Closure(AccessContext): RecordPage<T>  $read
 * @return RecordPage<T>
 */
function inReadTransaction(Closure $read): RecordPage
{
    return DB::connection()->transaction(static function () use ($read): RecordPage {
        app(ActorContext::class)->set(editor());

        return $read(editor());
    });
}

/**
 * Every page of the query, 20 at a time, as the entries' numbers.
 *
 * @return list<int>
 */
function everyMeasurement(AppFixtureMeasurementQuery $query): array
{
    $numbers = [];
    $cursor = null;

    do {
        $page = inReadTransaction(static fn (AccessContext $access): RecordPage => ($cursor instanceof TypeTableCursor ? $query->after($cursor) : $query)->page($access));

        foreach ($page->records as $record) {
            expect($record)->toBeInstanceOf(EntryRecord::class)
                ->and($record->record)->toBeInstanceOf(AppFixtureMeasurementRecord::class);
            $numbers[] = (int) substr($record->entry->toString(), -12);
        }

        expect(count($page->records))->toBeLessThanOrEqual(20);
        $cursor = $page->next;
    } while ($cursor instanceof TypeTableCursor);

    return $numbers;
}

/**
 * The seeds of the entries 1 to 200 in the two regions and 201 to 260 in WEST, which the editor's
 * regions do not reach.
 */
function seedMeasurements(): void
{
    PostgresTypeTables::seed(
        workbenchType('app:fixture_measurement'),
        ...measurements(1, 100, node(ROOT, NORTH)),
        ...measurements(101, 200, node(ROOT, SOUTH)),
        ...measurements(201, 260, node(ROOT, WEST)),
    );
}

it('filters, sorts and pages with a keyset through 200 rows in two regions, and returns only the rows the regions reach', function (): void {
    seedMeasurements();
    $query = app(AppFixtureMeasurementQuery::class)
        ->whereFixtureScale(FilterOperator::Eq, FixtureScaleChoice::FixtureCelsius)
        ->orderBy(AppFixtureMeasurementSortField::FixtureMeasuredAt, SortDirection::Descending)
        ->limit(20);

    expect(everyMeasurement($query))->toBe(range(200, 2, -2))
        ->and(everyMeasurement(app(AppFixtureMeasurementQuery::class)->orderBy(AppFixtureMeasurementSortField::FixtureReading)))->toBe(range(1, 200));
});

it('reads the same rows through a join to nodes as through the listed nodes of the regions', function (): void {
    seedMeasurements();
    $joined = new AppFixtureMeasurementQuery(
        new PostgresTypeTableReader(app(ConnectionResolverInterface::class), app(TypeCatalog::class), app(ReadAudit::class), regionNodeLimit: 0),
        app(AppFixtureMeasurementRecordFactory::class),
    );
    $station = static fn (AppFixtureMeasurementQuery $query): AppFixtureMeasurementQuery => $query
        ->whereFixtureStation(FilterOperator::In, 'st1', 'st3')
        ->whereFixtureMeasuredAt(FilterOperator::Gte, new DateTimeImmutable('2026-03-10T01:00:00Z'))
        ->orderBy(AppFixtureMeasurementSortField::FixtureReading, SortDirection::Descending);
    $expected = array_values(array_filter(range(200, 60, -1), static fn (int $number): bool => in_array($number % 5, [1, 3], true)));

    expect(everyMeasurement($station($joined)))->toBe($expected)
        ->and(everyMeasurement($station(app(AppFixtureMeasurementQuery::class))))->toBe($expected);
});

it('hydrates each row through the record factory into the owner\'s interface', function (): void {
    seedMeasurements();
    $page = inReadTransaction(static fn (AccessContext $access): RecordPage => app(AppFixtureMeasurementQuery::class)
        ->whereFixtureStation(FilterOperator::Eq, 'st2')
        ->orderBy(AppFixtureMeasurementSortField::FixtureMeasuredAt)
        ->limit(1)
        ->page($access));
    $record = $page->records[0]->record;

    expect($page->records[0]->entry->equals(entryId(2)))->toBeTrue()
        ->and($record)->toBeInstanceOf(AppFixtureMeasurementRecord::class)
        ->and($record->fixtureScale)->toBe(FixtureScaleChoice::FixtureCelsius)
        ->and($record->fixtureReading)->toBe('1')
        ->and($record->fixtureMeasuredAt->format(DATE_ATOM))->toBe('2026-03-10T00:02:00+00:00')
        ->and($record->fixtureStation)->toBe('st2')
        ->and($record->fixtureSamples)->toBe(2)
        ->and($record->fixtureNote)->toBeNull();
});

it('pages the articles newest first with the ones without a day first, through the keyset', function (): void {
    PostgresTypeTables::seed(workbenchType('app:fixture_article'), ...array_map(static fn (int $number): TypeTableSeed => new TypeTableSeed(
        entryId(300 + $number),
        node(ROOT, $number % 2 === 0 ? NORTH : SOUTH),
        new AppFixtureArticle(
            fixtureBody: null,
            fixtureEmbargo: null,
            fixtureFeatured: $number % 4 === 0,
            fixturePublishedOn: $number % 7 === 0 ? null : new DateTimeImmutable(sprintf('2026-01-%02dT00:00:00Z', 1 + intdiv($number, 2))),
            fixtureReadingMinutes: $number,
            fixtureSlug: null,
            fixtureSources: null,
            fixtureTitle: 'Article '.$number,
            fixtureTopics: null,
            ext: new AppFixtureArticleExt(new AppFixtureArticleFixtureaddonFields(fixtureSlug: 'article-'.$number)),
        )->toFieldValues(),
    ), range(1, 45)));
    $numbers = [];
    $cursor = null;

    do {
        $query = app(AppFixtureArticleQuery::class)->orderBy(AppFixtureArticleSortField::FixturePublishedOn, SortDirection::Descending)->limit(4);
        $page = inReadTransaction(static fn (AccessContext $access): RecordPage => ($cursor instanceof TypeTableCursor ? $query->after($cursor) : $query)->page($access));

        foreach ($page->records as $record) {
            expect($record->record)->toBeInstanceOf(AppFixtureArticleRecord::class);
            $numbers[] = (int) substr($record->entry->toString(), -12) - 300;
        }

        $cursor = $page->next;
    } while ($cursor instanceof TypeTableCursor);

    $undated = [42, 35, 28, 21, 14, 7];
    $dated = array_values(array_diff(range(1, 45), $undated));
    usort($dated, static fn (int $a, int $b): int => [intdiv($b, 2), $b] <=> [intdiv($a, 2), $a]);

    expect($numbers)->toBe([...$undated, ...$dated]);
});

it('costs the same queries for a page of 20 whether 20 or 200 rows match (GUARDRAILS 4.1)', function (): void {
    $type = workbenchType('app:fixture_measurement');
    $count = static function (): int {
        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $before = $queries;
        $page = inReadTransaction(static function (AccessContext $access) use (&$queries, &$before): RecordPage {
            $before = $queries;

            return app(AppFixtureMeasurementQuery::class)
                ->orderBy(AppFixtureMeasurementSortField::FixtureMeasuredAt, SortDirection::Descending)
                ->limit(20)
                ->page($access);
        });

        expect($page->records)->toHaveCount(20);

        return $queries - $before;
    };

    PostgresTypeTables::seed($type, ...measurements(1, 20, node(ROOT, NORTH)), ...measurements(201, 260, node(ROOT, WEST)));
    $twenty = $count();
    PostgresTypeTables::seed($type, ...measurements(21, 100, node(ROOT, NORTH)), ...measurements(101, 200, node(ROOT, SOUTH)));
    $twoHundred = $count();

    expect($twenty)->toBe(2)
        ->and($twoHundred)->toBe($twenty);
});
