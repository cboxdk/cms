<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\EntryRecord;
use Cbox\Cms\Contracts\TypeTables\RecordPage;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Core\Tests\TypeTables\PostgresTypeTables;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureArticle\AppFixtureArticleQuery;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\FixtureBylineFixtureRoleChoice;

/*
 * The attributes and the sort key of relations (PRD 5.11, B2-T75) through the real command pipeline
 * and the generated query builder on Postgres. fixture_article's fixture_byline credits entries
 * with a role, an attribute of each target, which is validated like a field, kept in the revision
 * and stored in relations.meta, and which a partial index serves; fixture_related and
 * fixture_byline are filterable, so their released edges carry fixture_published_on as their sort
 * key, written by the release in its transaction. On 50,000 edges of fixture_related, the newest 20
 * sources of one target are an index scan with the sort key (EXPLAIN (ANALYZE)), and a filter on
 * the role reads the role's partial index.
 */

const ATTRIBUTE_PLACEMENT = '0192a0c0-0000-7000-8000-0000000007f1';

const ATTRIBUTE_ROOT = '0192a0c0-0000-7000-8000-00000000a101';

const ATTRIBUTE_SECTION = '0192a0c0-0000-7000-8000-00000000a102';

const ATTRIBUTE_READER = '0192a0c0-0000-7000-8000-00000000a1a1';

/** The targets of the bulk sources, which each source relates to five of. */
const ATTRIBUTE_TARGETS = 20;

/** The bulk sources, each with five edges of fixture_related: 50,000 edges. */
const ATTRIBUTE_SOURCES = 10_000;

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

function attributeEntry(int $number): EntryId
{
    return EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 500_000 + $number));
}

/**
 * A byline of the targets, each with its role and, when given, its credit note.
 *
 * @param  list<array{EntryId, string, 2?: string}>  $credits
 */
function byline(array $credits): ListValue
{
    return new ListValue(...array_map(static fn (array $credit): GroupValue => new GroupValue(EntryFields::map([
        'entry' => new TextValue($credit[0]->toString()),
        'fixture_role' => new TextValue($credit[1]),
        ...(isset($credit[2]) ? ['fixture_credit_note' => new TextValue($credit[2])] : []),
    ])), $credits));
}

/**
 * The article's fields with its byline and the day it was published.
 *
 * @param  array<string, FieldValue>  $more
 */
function credited(ListValue $byline, string $publishedOn = '2026-03-09', array $more = []): FieldValues
{
    $fields = [];

    foreach (EntryFields::article()->own->fields as $named) {
        $fields[$named->handle->value] = $named->value;
    }

    return EntryFields::of([...$fields, ...$more, 'fixture_byline' => $byline, 'fixture_published_on' => new DateValue($publishedOn)]);
}

/**
 * The edges of the entry as "<stage> <field> <ordinal> <target's number> <meta> <filter key> <sort key>",
 * sorted, read as the superuser past row level security.
 *
 * @return list<string>
 */
function attributeEdges(EntryId $entry): array
{
    $edges = [];

    foreach (StorageTables::superuser()->table('relations')->where('source_entry_id', $entry->toString())
        ->orderBy('stage')->orderBy('field')->orderBy('ordinal')->get() as $edge) {
        $target = is_string($edge->target_entry_id) ? $edge->target_entry_id : '';
        $edges[] = implode(' ', [
            is_string($edge->stage) ? $edge->stage : '',
            is_string($edge->field) ? $edge->field : '',
            is_int($edge->ordinal) ? (string) $edge->ordinal : '',
            (string) ((int) substr($target, -12) - 500_000),
            is_string($edge->meta) ? $edge->meta : 'null',
            is_string($edge->filter_key) ? $edge->filter_key : 'null',
            is_string($edge->sort_key) ? $edge->sort_key : 'null',
        ]);
    }

    return $edges;
}

/**
 * The placement structure and a publishing world whose actor reaches north's section, with the
 * articles 1 to $count created there as the targets the tests credit.
 *
 * @return array{PublishingWorld, PlacementStructure, list<EntryId>}
 */
function attributeWorld(int $count = 3): array
{
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->northSection], seed: 13);
    $targets = [];

    foreach (range(1, $count) as $number) {
        $target = attributeEntry($number);
        expect($world->createEntry($target, EntryWorld::ARTICLE, EntryFields::article('Article '.$number), $structure->northSection, 'target-'.$number)->outcome())->toBe(Outcome::Committed);
        $targets[] = $target;
    }

    return [$world, $structure, $targets];
}

function attributeNode(string ...$ids): NodePath
{
    return new NodePath(implode('.', array_map(static fn (string $id): string => str_replace('-', '', $id), $ids)));
}

/**
 * A reader whose region reaches the bulk entries' section.
 */
function attributeReader(): AccessContext
{
    return new AccessContext(
        new ActorPrincipal(ActorId::fromString(ATTRIBUTE_READER), [], IssuerKind::Service, ClassificationAccess::Internal),
        [new AccessRegion(attributeNode(ATTRIBUTE_ROOT, ATTRIBUTE_SECTION))],
        ClassificationAccess::Internal,
    );
}

/**
 * The bulk sources' day: one of 2,000 days from 2020-01-01, so many sources share a day.
 */
function attributeDay(int $source): string
{
    return date('Y-m-d', strtotime(sprintf('2020-01-01 +%d days', ($source * 7919) % 2000)) ?: 0);
}

/**
 * ATTRIBUTE_SOURCES released articles in one section, written as the superuser in a few
 * statements: source n relates to the five targets (n + 7k) mod 20 + 1 for k from 0 to 4 in
 * fixture_related, 50,000 edges, and credits the target n mod 20 + 1 in fixture_byline with the
 * role n mod 3, each released edge with the source's fixture_published_on as its sort key. Every
 * fourth source has no day, so its sort key is null. Then ANALYZE, so the planner knows the tables.
 */
function seedAttributeSources(): void
{
    $type = app(TypeCatalog::class)->named(new TypeName(EntryWorld::ARTICLE)) ?? throw new LogicException('The workbench has no article type.');
    $superuser = StorageTables::superuser();
    $root = ATTRIBUTE_ROOT;
    $section = ATTRIBUTE_SECTION;
    $path = attributeNode(ATTRIBUTE_ROOT)->value;
    $sectionPath = attributeNode(ATTRIBUTE_ROOT, ATTRIBUTE_SECTION)->value;
    $typeId = $type->id->toString();
    $created = PostgresTypeTables::CREATED_AT;
    $id = "('0192a0c0-0000-7000-8000-' || lpad((500000 + n)::text, 12, '0'))::uuid";
    $target = static fn (string $offset): string => "('0192a0c0-0000-7000-8000-' || lpad((500000 + ({$offset}) % 20 + 1)::text, 12, '0'))::uuid";
    $day = "case when n % 4 = 0 then null else date '2020-01-01' + ((n * 7919) % 2000) end";
    $sources = sprintf('generate_series(%d, %d) as n', ATTRIBUTE_TARGETS + 1, ATTRIBUTE_TARGETS + ATTRIBUTE_SOURCES);
    $all = sprintf('generate_series(1, %d) as n', ATTRIBUTE_TARGETS + ATTRIBUTE_SOURCES);

    $superuser->statement("insert into nodes (id, parent_id, kind, path, mount_source_id, version, created_at) values ('{$root}', null, 'site', '{$path}', null, 1, '{$created}'), ('{$section}', '{$root}', 'section', '{$sectionPath}', null, 1, '{$created}')");
    $superuser->statement("insert into entries (id, type_id, home_node_id, owner_actor_id, lifecycle, version, created_at) select {$id}, '{$typeId}', '{$section}', null, 'active', 1, '{$created}' from {$all}");
    $superuser->statement("insert into variant_heads (entry_id, variant, schema_version, release_state, version, created_at) select {$id}, 'shared', {$type->version}, 'unreleased', 1, '{$created}' from {$all}");
    $superuser->statement("insert into app__fixture_article (cms_entry_id, cms_locale, cms_stage, cms_home_node, fixture_featured, fixture_title, fixture_published_on) select {$id}, 'shared', 'released', '{$section}', false, 'Article ' || n, case when n <= 20 then null else {$day} end from {$all}");
    $superuser->statement(sprintf(
        "insert into relations (source_entry_id, variant, stage, field, ordinal, target_entry_id, meta, filter_key, sort_key) select %s, 'shared', 'released', 'fixture_related', k, %s, null, null, to_char(%s, 'YYYY-MM-DD') from %s cross join generate_series(0, 4) as k",
        $id,
        $target('n + 7 * k'),
        $day,
        $sources,
    ));
    $superuser->statement(sprintf(
        "insert into relations (source_entry_id, variant, stage, field, ordinal, target_entry_id, meta, filter_key, sort_key) select %s, 'shared', 'released', 'fixture_byline', 0, %s, jsonb_build_object('fixture_role', (array['fixture_author', 'fixture_photo', 'fixture_contributor'])[n %% 3 + 1]), (array['fixture_author', 'fixture_photo', 'fixture_contributor'])[n %% 3 + 1], to_char(%s, 'YYYY-MM-DD') from %s",
        $id,
        $target('n'),
        $day,
        $sources,
    ));
    $superuser->statement('analyze relations, app__fixture_article, entries, nodes');
}

/**
 * The page in a read transaction of the app role with the reader's context set, as the query
 * pipeline reads it, and the statement it ran on the type table with its bindings.
 *
 * @template T of object
 *
 * @param  Closure(AccessContext): RecordPage<T>  $read
 * @return array{RecordPage<T>, string, list<mixed>}
 */
function readAttributePage(Closure $read): array
{
    /** @var ?string $sql */
    $sql = null;
    /** @var list<mixed> $bindings */
    $bindings = [];

    $page = DB::connection()->transaction(static function () use ($read, &$sql, &$bindings): RecordPage {
        app(ActorContext::class)->set(attributeReader());
        DB::listen(static function (QueryExecuted $query) use (&$sql, &$bindings): void {
            if (str_contains($query->sql, 'app__fixture_article')) {
                $sql = $query->sql;
                $bindings = array_values($query->bindings);
            }
        });

        return $read(attributeReader());
    });

    if (! is_string($sql)) {
        throw new LogicException('The page ran no statement on the type table.');
    }

    return [$page, $sql, $bindings];
}

/**
 * The plan of the statement as EXPLAIN (ANALYZE, FORMAT JSON) gives it, run in a read transaction
 * of the app role with the reader's context set, as the page ran.
 *
 * @param  list<mixed>  $bindings
 * @return array<mixed>
 */
function attributePlan(string $sql, array $bindings): array
{
    return DB::connection()->transaction(static function () use ($sql, $bindings): array {
        app(ActorContext::class)->set(attributeReader());
        $row = DB::connection()->selectOne('explain (analyze, format json) '.$sql, $bindings);
        $text = is_object($row) ? (get_object_vars($row)['QUERY PLAN'] ?? null) : null;
        $plan = is_string($text) ? json_decode($text, true) : null;

        return is_array($plan) && is_array($plan[0] ?? null) && is_array($plan[0]['Plan'] ?? null) ? $plan[0]['Plan'] : throw new LogicException('EXPLAIN gave no plan.');
    });
}

/**
 * Every node of the plan, depth first.
 *
 * @param  array<mixed>  $plan
 * @return list<array<mixed>>
 */
function planNodes(array $plan): array
{
    $nodes = [$plan];

    foreach (is_array($plan['Plans'] ?? null) ? $plan['Plans'] : [] as $child) {
        if (is_array($child)) {
            $nodes = [...$nodes, ...planNodes($child)];
        }
    }

    return $nodes;
}

/**
 * The scans of the plan on the index.
 *
 * @param  array<mixed>  $plan
 * @return list<array<mixed>>
 */
function indexScans(array $plan, string $index): array
{
    return array_values(array_filter(planNodes($plan), static fn (array $node): bool => ($node['Index Name'] ?? null) === $index));
}

/**
 * The entries of a page as their numbers of attributeEntry().
 *
 * @template T of object
 *
 * @param  RecordPage<T>  $page
 * @return list<int>
 */
function attributeNumbers(RecordPage $page): array
{
    return array_map(static fn (EntryRecord $record): int => (int) substr($record->entry->toString(), -12) - 500_000, $page->records);
}

it('refuses a role the byline does not declare, at the field\'s path, and keeps nothing of the call', function (): void {
    [$world, $structure, $targets] = attributeWorld(1);
    $article = attributeEntry(100);

    $result = $world->createEntry($article, EntryWorld::ARTICLE, credited(byline([[$targets[0], 'fixture_author'], [$targets[0], 'fixture_editor']])), $structure->northSection, 'create-unknown-role');

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and($result->errors[0]->code->value)->toBe('validation_failed')
        ->and(array_map(static fn (CatalogError $error): array => [$error->code->value, $error->path?->toString()], array_slice($result->errors, 1)))->toBe([
            ['validation_not_an_option', 'fields.fixture_byline[1].fixture_role'],
        ])
        ->and(StorageTables::superuser()->table('entries')->where('id', $article->toString())->count())->toBe(0)
        ->and(attributeEdges($article))->toBe([]);
});

it('keeps each target\'s attributes in the revision and in relations.meta, and writes the sort key onto the released edges at release', function (): void {
    [$world, $structure, $targets] = attributeWorld();
    $article = attributeEntry(101);
    $placement = PlacementId::fromString(ATTRIBUTE_PLACEMENT);
    $fields = credited(
        byline([[$targets[0], 'fixture_author'], [$targets[1], 'fixture_photo', 'Photos by the desk'], [$targets[0], 'fixture_contributor']]),
        more: ['fixture_related' => new ListValue(new TextValue($targets[2]->toString()))],
    );

    expect($world->createEntry($article, EntryWorld::ARTICLE, $fields, $structure->northSection, 'create-article')->outcome())->toBe(Outcome::Committed)
        ->and(attributeEdges($article))->toBe([
            'draft fixture_byline 0 1 {"fixture_role": "fixture_author"} fixture_author null',
            'draft fixture_byline 1 2 {"fixture_role": "fixture_photo", "fixture_credit_note": "Photos by the desk"} fixture_photo null',
            'draft fixture_byline 2 1 {"fixture_role": "fixture_contributor"} fixture_contributor null',
            'draft fixture_related 0 3 null null null',
        ]);

    expect($world->place($placement, $article, $structure->northSection, $structure->north, 'krediteret', 'place-article')->outcome())->toBe(Outcome::Committed)
        ->and($world->publish($article, 1, 1, $placement, 1, 'publish-article')->outcome())->toBe(Outcome::Committed)
        ->and(array_values(array_filter(attributeEdges($article), static fn (string $edge): bool => str_starts_with($edge, 'released'))))->toBe([
            'released fixture_byline 0 1 {"fixture_role": "fixture_author"} fixture_author 2026-03-09',
            'released fixture_byline 1 2 {"fixture_role": "fixture_photo", "fixture_credit_note": "Photos by the desk"} fixture_photo 2026-03-09',
            'released fixture_byline 2 1 {"fixture_role": "fixture_contributor"} fixture_contributor 2026-03-09',
            'released fixture_related 0 3 null null 2026-03-09',
        ]);

    // The attributes are kept in the revision: the type row reads them back with the targets.
    $row = StorageTables::superuser()->table('app__fixture_article')->where('cms_entry_id', $article->toString())->where('cms_stage', 'released')->value('fixture_byline');
    expect(is_string($row) ? json_decode($row, true) : null)->toBe([
        ['entry' => $targets[0]->toString(), 'fixture_role' => 'fixture_author'],
        ['entry' => $targets[1]->toString(), 'fixture_role' => 'fixture_photo', 'fixture_credit_note' => 'Photos by the desk'],
        ['entry' => $targets[0]->toString(), 'fixture_role' => 'fixture_contributor'],
    ]);

    // A revise changes the draft and its day; the released edges keep their sort key until the release.
    $revised = $world->revise($article, 2, credited(byline([[$targets[1], 'fixture_author']]), '2026-04-01'), 'revise-article');

    expect($revised->outcome())->toBe(Outcome::Committed)
        ->and(attributeEdges($article))->toBe([
            'draft fixture_byline 0 2 {"fixture_role": "fixture_author"} fixture_author null',
            'released fixture_byline 0 1 {"fixture_role": "fixture_author"} fixture_author 2026-03-09',
            'released fixture_byline 1 2 {"fixture_role": "fixture_photo", "fixture_credit_note": "Photos by the desk"} fixture_photo 2026-03-09',
            'released fixture_byline 2 1 {"fixture_role": "fixture_contributor"} fixture_contributor 2026-03-09',
            'released fixture_related 0 3 null null 2026-03-09',
        ]);

    expect($world->releaseVariant($article, 'shared', 3, 3, 'release-article')->outcome())->toBe(Outcome::Committed)
        ->and(array_values(array_filter(attributeEdges($article), static fn (string $edge): bool => str_starts_with($edge, 'released'))))->toBe([
            'released fixture_byline 0 2 {"fixture_role": "fixture_author"} fixture_author 2026-04-01',
        ]);
});

it('reads the newest 20 sources of one target of 50,000 edges through an index scan with the sort key', function (): void {
    seedAttributeSources();
    $target = attributeEntry(7);
    [$page, $sql, $bindings] = readAttributePage(static fn (AccessContext $access): RecordPage => app(AppFixtureArticleQuery::class)
        ->whereFixtureRelated($target)
        ->orderByFixtureRelated()
        ->limit(20)
        ->page($access));

    // The expected page: the sources relating to the target, those without a day first (null is
    // the greatest value), then by day and entry, both descending.
    $expected = array_values(array_filter(
        range(ATTRIBUTE_TARGETS + 1, ATTRIBUTE_TARGETS + ATTRIBUTE_SOURCES),
        static fn (int $source): bool => array_any(range(0, 4), static fn (int $k): bool => ($source + 7 * $k) % 20 + 1 === 7),
    ));
    usort($expected, static fn (int $a, int $b): int => [$b % 4 === 0 ? '9999' : attributeDay($b), $b] <=> [$a % 4 === 0 ? '9999' : attributeDay($a), $a]);

    expect(StorageTables::superuser()->table('relations')->where('field', 'fixture_related')->count())->toBe(50_000)
        ->and(attributeNumbers($page))->toBe(array_slice($expected, 0, 20));

    $plan = attributePlan($sql, $bindings);
    $scans = indexScans($plan, 'relations_sorted');

    expect($scans)->toHaveCount(1)
        ->and($scans[0]['Scan Direction'] ?? null)->toBe('Backward')
        ->and($scans[0]['Actual Rows'] ?? null)->toBeLessThanOrEqual(21)
        ->and(array_filter(planNodes($plan), static fn (array $node): bool => in_array($node['Node Type'] ?? null, ['Sort', 'Incremental Sort'], true)))->toBe([]);

    // The next pages start the scan at the cursor's sort key, so a page deep in the listing reads
    // its own rows only.
    $cursor = $page->next;

    foreach (range(1, 30) as $number) {
        expect($cursor)->toBeInstanceOf(TypeTableCursor::class);
        assert($cursor instanceof TypeTableCursor);
        $after = $cursor;
        [$page, $sql, $bindings] = readAttributePage(static fn (AccessContext $access): RecordPage => app(AppFixtureArticleQuery::class)
            ->whereFixtureRelated($target)
            ->orderByFixtureRelated()
            ->after($after)
            ->limit(20)
            ->page($access));

        expect(attributeNumbers($page))->toBe(array_slice($expected, 20 * $number, 20));
        $cursor = $page->next;
    }

    $deep = indexScans(attributePlan($sql, $bindings), 'relations_sorted');

    expect($deep)->toHaveCount(1)
        ->and($deep[0]['Index Cond'] ?? '')->toContain('sort_key')
        ->and($deep[0]['Actual Rows'] ?? null)->toBeLessThanOrEqual(21);
});

it('filters on a role through its partial index, and pages the role\'s sources by the sort key', function (): void {
    seedAttributeSources();
    $target = attributeEntry(4);
    [$page, $sql, $bindings] = readAttributePage(static fn (AccessContext $access): RecordPage => app(AppFixtureArticleQuery::class)
        ->whereFixtureByline($target, FixtureBylineFixtureRoleChoice::FixturePhoto)
        ->orderByFixtureByline()
        ->limit(20)
        ->page($access));

    $expected = array_values(array_filter(
        range(ATTRIBUTE_TARGETS + 1, ATTRIBUTE_TARGETS + ATTRIBUTE_SOURCES),
        static fn (int $source): bool => $source % 20 + 1 === 4 && $source % 3 === 1,
    ));
    usort($expected, static fn (int $a, int $b): int => [$b % 4 === 0 ? '9999' : attributeDay($b), $b] <=> [$a % 4 === 0 ? '9999' : attributeDay($a), $a]);

    expect(attributeNumbers($page))->toBe(array_slice($expected, 0, 20))
        ->and(indexScans(attributePlan($sql, $bindings), 'app__fixture_article__fixture_byline__fixture_role'))->toHaveCount(1);

    // The filter without the order reads the same partial index.
    [$unordered, $filterSql, $filterBindings] = readAttributePage(static fn (AccessContext $access): RecordPage => app(AppFixtureArticleQuery::class)
        ->whereFixtureByline($target, FixtureBylineFixtureRoleChoice::FixturePhoto)
        ->limit(100)
        ->page($access));
    $ascending = $expected;
    sort($ascending);

    expect(attributeNumbers($unordered))->toBe(array_slice($ascending, 0, 100))
        ->and(indexScans(attributePlan($filterSql, $filterBindings), 'app__fixture_article__fixture_byline__fixture_role'))->toHaveCount(1);
});
