<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Adapter\PostgresRouteReader;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * path.resolve through the query pipeline on Postgres as the anonymous principal (PRD 5.8, 5.9,
 * 5.10, GUARDRAILS 4.1 and 5), on the structure of PlacementWorld::seed(): the sites north and south,
 * each with the section "/nyheder", and the mount "/national" on south of north's section. An
 * article published on north's section resolves there, and through the mount on south, identical
 * to the source, whose placement stays canonical; row level security lets the anonymous context
 * read the routes, the routed nodes and what is public. Both resolutions cost the same queries
 * whether 20 or 200 placements are below the section.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const RESOLVED_ENTRY = '0192a0c0-0000-7000-8000-0000000035e9';

const RESOLVED_PLACEMENT = '0192a0c0-0000-7000-8000-0000000035c9';

/**
 * The seeded structure, the mount on south and a fixture article published below north's section
 * with the slug "harbour".
 */
function resolvedWorld(): PlacementStructure
{
    $structure = PlacementWorld::seed();
    $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
    $fixtures = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 935, clock: $clock));
    $mount = $fixtures->mount($structure->south->root, $structure->northSection);
    $fixtures->route($structure->south, new Locale('da'), '/national', $mount);

    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    $entry = EntryId::fromString(RESOLVED_ENTRY);
    $placement = PlacementId::fromString(RESOLVED_PLACEMENT);
    $created = $world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article('The harbour opens'), $structure->northSection, 'resolve-entry');
    $placed = $world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour', 'resolve-place');
    $published = $world->publish($entry, 1, 1, $placement, 1, 'resolve-publish');

    if ($created->outcome() !== Outcome::Committed || $placed->outcome() !== Outcome::Committed || $published->outcome() !== Outcome::Committed) {
        throw new LogicException('The article to resolve was not published.');
    }

    return $structure;
}

/**
 * $count live, canonical placements of active entries of their own below the node in da, written
 * as the superuser.
 */
function resolvedNeighbours(StructureNode $node, int $count, int $first): void
{
    $superuser = StorageTables::superuser();

    for ($number = $first; $number < $first + $count; $number++) {
        $entry = sprintf('0192a0c0-0000-7000-9000-%012d', $number);
        $placement = sprintf('0192a0c0-0000-7000-a000-%012d', $number);
        $superuser->table('entries')->insert([...StorageTables::entry($entry), 'home_node_id' => $node->id->toString()]);
        $superuser->table('placements')->insert(StorageTables::placement($placement, $entry));
        $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node->id->toString()));
        $superuser->table('placement_locales')->insert(StorageTables::placementLocale([
            'placement_id' => $placement, 'entry_id' => $entry, 'node_id' => $node->id->toString(),
            'slug' => 'neighbour-'.$number, 'visibility' => 'live', 'canonical' => true,
        ]));
    }
}

/**
 * The query pipeline with path.resolve on Postgres, the kernel's credential verifier, access
 * resolver, read audit and read transaction, an authorizer that allows, and the clock an hour
 * after the publish.
 */
function resolvePipeline(): QueryPipeline
{
    $connections = app(ConnectionResolverInterface::class);
    $action = new ResolvePathAction(
        new PostgresRouteReader($connections),
        new SiteHosts([
            new ConfiguredSite(new SiteHandle('north'), new SiteOrigin('https://north.example')),
            new ConfiguredSite(new SiteHandle('south'), new SiteOrigin('https://south.example')),
        ]),
        app(TypeCatalog::class),
        new FakeClock(new DateTimeImmutable(EntryWorld::NOW)->modify('+1 hour')),
    );

    return new QueryPipeline(
        new FakeQueryActions([ResolvePath::class => ProbeQueryBinding::of($action, 'path.resolve', 1)]),
        app(CredentialVerifier::class),
        app(AccessResolver::class),
        new FakeQueryAuthorizer,
        new QuerySettings(new QueryCost(ResolvePathAction::COST), new QueryCost(ResolvePathAction::COST)),
        app(ReadableFields::class),
        app(ReadAudit::class),
        app(QueryTransaction::class),
        new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
    );
}

function resolveAnonymously(QueryPipeline $pipeline, string $host, string $path): QueryResult
{
    return $pipeline->run(new QueryCall(new ResolvePath(new Host($host), new Locale('da'), new RequestPath($path)), null));
}

function resolvedPath(QueryResult $result): ResolvedPath
{
    return $result->result instanceof ResolvedPath ? $result->result : throw new LogicException('The read was not answered with a resolved path.');
}

/**
 * @return list<string>
 */
function resolvedContentKeys(QueryResult $result): array
{
    return array_map(static fn (DependencyKey $key): string => $key->toString(), $result->contentKeys);
}

it('resolves a placement on its site and through a mount on a second site, as the anonymous principal', function (): void {
    $structure = resolvedWorld();
    $pipeline = resolvePipeline();
    $section = $structure->northSection->id->toString();

    $direct = resolveAnonymously($pipeline, 'north.example', '/nyheder/harbour');
    $mounted = resolveAnonymously($pipeline, 'south.example', '/national/harbour');
    $elsewhere = resolveAnonymously($pipeline, 'south.example', '/nyheder/harbour');
    $direct = [$direct, resolvedPath($direct)];
    $mounted = [$mounted, resolvedPath($mounted)];

    expect($direct[1]->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($direct[1]->explanation->site->site?->toString())->toBe($structure->north->id->toString())
        ->and($direct[1]->explanation->route?->route)->toBe('/nyheder')
        ->and($direct[1]->explanation->node?->node->toString())->toBe($section)
        ->and($direct[1]->explanation->mount)->toBeNull()
        ->and($direct[1]->explanation->placement?->placement?->toString())->toBe(RESOLVED_PLACEMENT)
        ->and($direct[1]->explanation->visibility?->decision)->toBe(VisibilityDecision::Visible)
        ->and([$direct[1]->explanation->canonical?->url, $direct[1]->explanation->canonical?->here])->toBe(['https://north.example/nyheder/harbour', true])
        ->and(resolvedContentKeys($direct[0]))->toBe(['e-'.RESOLVED_ENTRY, 'n-'.$section])
        ->and($mounted[1]->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($mounted[1]->explanation->site->site?->toString())->toBe($structure->south->id->toString())
        ->and($mounted[1]->explanation->route?->route)->toBe('/national')
        ->and($mounted[1]->explanation->mount?->source->toString())->toBe($section)
        ->and($mounted[1]->explanation->placement?->placement?->toString())->toBe(RESOLVED_PLACEMENT)
        ->and([$mounted[1]->explanation->canonical?->url, $mounted[1]->explanation->canonical?->here])->toBe(['https://north.example/nyheder/harbour', false])
        ->and(resolvedContentKeys($mounted[0]))->toBe(['e-'.RESOLVED_ENTRY, 'n-'.$section])
        ->and(resolvedPath($elsewhere)->outcome())->toBe(ResolveOutcome::NoPlacement);
});

it('costs the same queries to resolve the placement and the mount whether 20 or 200 placements are below the node', function (): void {
    $structure = resolvedWorld();
    $pipeline = resolvePipeline();
    $counts = [];
    $placed = 0;

    foreach ([20, 200] as $count) {
        resolvedNeighbours($structure->northSection, $count - $placed, 35000 + $placed);
        $placed = $count;
        $measured = [];

        foreach (['north.example' => '/nyheder/harbour', 'south.example' => '/national/harbour'] as $host => $path) {
            $connection = DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $result = resolveAnonymously($pipeline, $host, $path);
            $measured[] = [resolvedPath($result)->outcome(), count($connection->getQueryLog())];
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $counts[$count] = $measured;
    }

    expect(StorageTables::superuser()->table('placement_locales')->where('node_id', $structure->northSection->id->toString())->count())->toBe(201)
        ->and(array_column($counts[20], 0))->toBe([ResolveOutcome::Resolved, ResolveOutcome::Resolved])
        ->and(array_column($counts[200], 0))->toBe([ResolveOutcome::Resolved, ResolveOutcome::Resolved])
        ->and($counts[200][0][1])->toBe($counts[20][0][1])
        ->and($counts[200][1][1])->toBe($counts[20][1][1]);
});

it('lets the anonymous context read the routes and the kind of a node that has one, and no node row', function (): void {
    $structure = resolvedWorld();
    $connection = DB::connection();
    $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
    $unrouted = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 936, clock: $clock))->node($structure->northSection, 'storage');
    $routedNode = static fn (StructureNode $node): array => StorageTables::texts($connection, "select concat_ws(' ', kind, coalesce(mount_source_id::text, '-'), root_id) as value from cms_routed_node(?::uuid)", [$node->id->toString()]);
    $root = $structure->north->root->id->toString();

    $withoutContext = $routedNode($structure->northSection);
    $connection->beginTransaction();

    try {
        new ActorContext(app(ConnectionResolverInterface::class))->set(AccessContext::anonymous());
        $routes = $connection->table('node_routes')->count();
        $nodes = $connection->table('nodes')->count();
        $section = $routedNode($structure->northSection);
        $storage = $routedNode($unrouted);
    } finally {
        $connection->rollBack();
    }

    expect($withoutContext)->toBe([])
        ->and($routes)->toBe(6)
        ->and($nodes)->toBe(0)
        ->and($section)->toBe(['section - '.$root])
        ->and($storage)->toBe([]);
});
