<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Delivery\Domain\PathAnswers;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Adapter\PostgresRouteReader;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\RedirectStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Structure\NodeWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * The redirects of PRD 5.9 for content that moves without changing identity, through the real
 * command pipeline and path.resolve as the anonymous principal on Postgres: placement.set_slug
 * keeps the slug a placement had in the slug history, pointing at the placement, so every URL it
 * had answers 301 straight to the current one; node.set_route version 2 keeps the route a node had
 * as a prefix redirect, so a path below the old route answers 301 below the new one, a renamed slug
 * included, in one hop; a rename keeps the placement visible; a slug another placement has is
 * placement_slug_taken; and the old URL of a withdrawn placement answers 410. The lookups cost the
 * same statements whatever the number of history rows and placements below the node.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const SLUG_HISTORY_ENTRY = '0192a0c0-0000-7000-8000-00000000a1e1';

const SLUG_HISTORY_PLACEMENT = '0192a0c0-0000-7000-8000-00000000a1c1';

const SLUG_HISTORY_OTHER_ENTRY = '0192a0c0-0000-7000-8000-00000000a1e2';

const SLUG_HISTORY_OTHER_PLACEMENT = '0192a0c0-0000-7000-8000-00000000a1c2';

/**
 * The world, its structure and a fixture article placed below north's section with the slug
 * "harbour", live from EntryWorld::NOW, at placement version 2.
 *
 * @return array{NodeWorld, PlacementStructure}
 */
function slugHistoryWorld(): array
{
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root, $structure->south->root]);
    $entry = EntryId::fromString(SLUG_HISTORY_ENTRY);
    $placement = PlacementId::fromString(SLUG_HISTORY_PLACEMENT);

    slugHistoryCommitted($world->createEntry($entry, $structure->northSection, 'slug-history-entry'));
    slugHistoryCommitted($world->place($placement, $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'slug-history-place'));
    slugHistoryCommitted($world->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'slug-history-live'));

    return [$world, $structure];
}

function slugHistoryCommitted(WriteResult $result): WriteResult
{
    return $result->outcome() === Outcome::Committed
        ? $result
        : throw new LogicException(sprintf('A command was not committed: %s.', implode(', ', array_map(static fn (CatalogError $error): string => $error->code->value.' '.$error->message, $result->errors))));
}

/**
 * The query pipeline with path.resolve on Postgres as PathResolutionTest builds it, for north.example
 * and south.example, with the clock an hour after EntryWorld::NOW.
 */
function slugHistoryPipeline(): QueryPipeline
{
    $action = new ResolvePathAction(
        new PostgresRouteReader(app(ConnectionResolverInterface::class)),
        new SiteHosts([
            new ConfiguredSite(new SiteHandle('north'), new SiteOrigin('https://north.example'), [new Locale('da')]),
            new ConfiguredSite(new SiteHandle('south'), new SiteOrigin('https://south.example'), [new Locale('da')]),
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

function slugHistoryResolve(QueryPipeline $pipeline, string $path): ResolvedPath
{
    $result = $pipeline->run(new QueryCall(new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath($path)), null));

    return $result->result instanceof ResolvedPath ? $result->result : throw new LogicException(sprintf('%s was not answered with a resolved path.', $path));
}

/**
 * The resolution as "<outcome> <redirect reason> <redirect URL>", or "<outcome> <catalog code>"
 * when it does not redirect.
 */
function slugHistoryAnswer(ResolvedPath $resolved): string
{
    $explanation = $resolved->explanation;

    return $explanation->redirect instanceof RedirectStep
        ? sprintf('%s %s %s', $explanation->outcome->value, $explanation->redirect->reason->value, $explanation->redirect->url)
        : sprintf('%s %s', $explanation->outcome->value, PathAnswers::code($explanation)->value ?? 'ok');
}

/**
 * The number of statements the default connection runs for the resolution, with its answer.
 *
 * @return array{string, int}
 */
function slugHistoryCounted(QueryPipeline $pipeline, string $path): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $answer = slugHistoryAnswer(slugHistoryResolve($pipeline, $path));

        return [$answer, count($connection->getQueryLog())];
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

/**
 * $count history rows for the placement below the node in da, written as the superuser, as many
 * renames would leave them.
 */
function slugHistoryRows(StructureNode $node, int $count, int $first): void
{
    $changeset = StorageTables::superuser()->table('changeset_register')->value('changeset_id');

    for ($number = $first; $number < $first + $count; $number++) {
        StorageTables::superuser()->table('slug_history')->insert([
            'node_id' => $node->id->toString(), 'locale' => 'da', 'slug' => 'earlier-'.$number,
            'placement_id' => SLUG_HISTORY_PLACEMENT, 'changeset_id' => $changeset, 'created_at' => StorageTables::CREATED_AT,
        ]);
    }
}

/**
 * $count live, canonical placements of active entries of their own below the node in da, written
 * as the superuser.
 */
function slugHistoryNeighbours(StructureNode $node, int $count, int $first): void
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
 * @return list<string>
 */
function slugHistoryCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

it('answers every slug a placement had with 301 straight to its current URL after three renames, and keeps it visible', function (): void {
    [$world] = slugHistoryWorld();
    $placement = PlacementId::fromString(SLUG_HISTORY_PLACEMENT);

    slugHistoryCommitted($world->setSlug($placement, 2, 'havnen', 'rename-1'));
    slugHistoryCommitted($world->setSlug($placement, 3, 'kajen', 'rename-2'));
    slugHistoryCommitted($world->setSlug($placement, 4, 'molen', 'rename-3'));
    $pipeline = slugHistoryPipeline();
    $current = slugHistoryResolve($pipeline, '/nyheder/molen');

    expect(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/harbour')))->toBe('redirect slug_changed https://north.example/nyheder/molen')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/havnen')))->toBe('redirect slug_changed https://north.example/nyheder/molen')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/kajen')))->toBe('redirect slug_changed https://north.example/nyheder/molen')
        ->and(slugHistoryAnswer($current))->toBe('resolved ok')
        ->and($current->explanation->visibility?->decision)->toBe(VisibilityDecision::Visible)
        ->and(StorageTables::texts(StorageTables::superuser(), "select slug || ' ' || placement_id::text as value from slug_history order by slug"))
        ->toBe(['harbour '.SLUG_HISTORY_PLACEMENT, 'havnen '.SLUG_HISTORY_PLACEMENT, 'kajen '.SLUG_HISTORY_PLACEMENT])
        ->and(StorageTables::texts(StorageTables::superuser(), "select visibility || ' ' || slug as value from placement_locales where placement_id = ?::uuid", [SLUG_HISTORY_PLACEMENT]))
        ->toBe(['live molen']);
});

it('drops the history row of a slug the placement is renamed back to, so the live lookup answers it', function (): void {
    [$world] = slugHistoryWorld();
    $placement = PlacementId::fromString(SLUG_HISTORY_PLACEMENT);

    slugHistoryCommitted($world->setSlug($placement, 2, 'havnen', 'rename-1'));
    slugHistoryCommitted($world->setSlug($placement, 3, 'harbour', 'rename-back'));
    $pipeline = slugHistoryPipeline();

    expect(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/harbour')))->toBe('resolved ok')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/havnen')))->toBe('redirect slug_changed https://north.example/nyheder/harbour')
        ->and(StorageTables::texts(StorageTables::superuser(), 'select slug as value from slug_history'))->toBe(['havnen']);
});

it('refuses a slug another placement has below the node with placement_slug_taken and changes nothing', function (): void {
    [$world, $structure] = slugHistoryWorld();
    $other = EntryId::fromString(SLUG_HISTORY_OTHER_ENTRY);
    slugHistoryCommitted($world->createEntry($other, $structure->northSection, 'slug-history-other'));
    slugHistoryCommitted($world->place(PlacementId::fromString(SLUG_HISTORY_OTHER_PLACEMENT), $other, $structure->northSection, $structure->north, ['da' => 'fyret'], 'slug-history-other-place'));

    $taken = $world->setSlug(PlacementId::fromString(SLUG_HISTORY_PLACEMENT), 2, 'fyret', 'rename-taken');

    expect($taken->outcome())->toBe(Outcome::Rejected)
        ->and(slugHistoryCodes($taken))->toBe([ErrorCode::PlacementSlugTaken->value])
        ->and($taken->errors[0]->path?->toString())->toBe('slug')
        ->and(StorageTables::superuser()->table('slug_history')->count())->toBe(0)
        ->and(StorageTables::texts(StorageTables::superuser(), 'select slug as value from placement_locales where placement_id = ?::uuid', [SLUG_HISTORY_PLACEMENT]))->toBe(['harbour']);
});

it('answers 410 at the old URL of a withdrawn placement, as at its current one', function (): void {
    [$world] = slugHistoryWorld();
    slugHistoryCommitted($world->setSlug(PlacementId::fromString(SLUG_HISTORY_PLACEMENT), 2, 'havnen', 'rename-1'));
    StorageTables::superuser()->table('placement_locales')->where('placement_id', SLUG_HISTORY_PLACEMENT)->update(['visibility' => 'withdrawn', 'canonical' => false]);
    $pipeline = slugHistoryPipeline();
    $old = slugHistoryResolve($pipeline, '/nyheder/harbour');

    expect(slugHistoryAnswer($old))->toBe('not_visible '.ErrorCode::PathGone->value)
        ->and($old->explanation->visibility?->decision)->toBe(VisibilityDecision::PlacementWithdrawn)
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/havnen')))->toBe('not_visible '.ErrorCode::PathGone->value);
});

it('answers a path below a node\'s old route with 301 below its current route, a renamed slug in one hop, after two route changes', function (): void {
    [$world, $structure] = slugHistoryWorld();
    slugHistoryCommitted($world->setSlug(PlacementId::fromString(SLUG_HISTORY_PLACEMENT), 2, 'havnen', 'rename-1'));
    slugHistoryCommitted($world->changeRoute($structure->northSection->id, $structure->north, '/aktuelt', 'route-1'));
    slugHistoryCommitted($world->changeRoute($structure->northSection->id, $structure->north, '/seneste', 'route-2', version: 2));
    $pipeline = slugHistoryPipeline();

    expect(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/havnen')))->toBe('redirect route_changed https://north.example/seneste/havnen')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder/harbour')))->toBe('redirect route_changed https://north.example/seneste/havnen')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/aktuelt/havnen')))->toBe('redirect route_changed https://north.example/seneste/havnen')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/nyheder')))->toBe('redirect route_changed https://north.example/seneste')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/seneste/havnen')))->toBe('resolved ok')
        ->and(slugHistoryAnswer(slugHistoryResolve($pipeline, '/seneste/harbour')))->toBe('redirect slug_changed https://north.example/seneste/havnen')
        ->and(StorageTables::texts(StorageTables::superuser(), "select route || ' ' || node_id::text as value from node_route_history order by route"))
        ->toBe(['/aktuelt '.$structure->northSection->id->toString(), '/nyheder '.$structure->northSection->id->toString()])
        ->and(StorageTables::texts(StorageTables::superuser(), 'select route as value from node_routes where node_id = ?::uuid', [$structure->northSection->id->toString()]))->toBe(['/seneste']);
});

it('keeps refusing a node that has a route in node.set_route version 1, which version 2 moves', function (): void {
    [$world, $structure] = slugHistoryWorld();

    $first = $world->setRoute($structure->northSection->id, $structure->north, '/aktuelt', 'route-v1');

    expect($first->outcome())->toBe(Outcome::Rejected)
        ->and(StorageTables::superuser()->table('node_route_history')->count())->toBe(0);
});

it('costs the same statements for a placement with 1 and with 20 history rows, and for a node with 20 and 200 placements', function (): void {
    [$world, $structure] = slugHistoryWorld();
    slugHistoryCommitted($world->setSlug(PlacementId::fromString(SLUG_HISTORY_PLACEMENT), 2, 'havnen', 'rename-1'));
    $pipeline = slugHistoryPipeline();

    $oneRow = slugHistoryCounted($pipeline, '/nyheder/harbour');
    slugHistoryRows($structure->northSection, 19, 1);
    $twentyRows = slugHistoryCounted($pipeline, '/nyheder/harbour');

    slugHistoryCommitted($world->changeRoute($structure->northSection->id, $structure->north, '/aktuelt', 'route-1'));
    slugHistoryNeighbours($structure->northSection, 20, 41000);
    $twenty = [slugHistoryCounted($pipeline, '/nyheder/harbour'), slugHistoryCounted($pipeline, '/aktuelt/harbour')];
    slugHistoryNeighbours($structure->northSection, 180, 41020);
    $twoHundred = [slugHistoryCounted($pipeline, '/nyheder/harbour'), slugHistoryCounted($pipeline, '/aktuelt/harbour')];

    expect(StorageTables::superuser()->table('slug_history')->count())->toBe(20)
        ->and($oneRow[0])->toBe('redirect slug_changed https://north.example/nyheder/havnen')
        ->and($twentyRows)->toBe($oneRow)
        ->and($twenty[0][0])->toBe('redirect route_changed https://north.example/aktuelt/havnen')
        ->and($twenty[1][0])->toBe('redirect slug_changed https://north.example/aktuelt/havnen')
        ->and(StorageTables::superuser()->table('placement_locales')->where('node_id', $structure->northSection->id->toString())->count())->toBe(201)
        ->and($twoHundred)->toBe($twenty);
});

it('keeps both tables under forced row level security, holding no row without an actor context', function (): void {
    [$world] = slugHistoryWorld();
    slugHistoryCommitted($world->setSlug(PlacementId::fromString(SLUG_HISTORY_PLACEMENT), 2, 'havnen', 'rename-1'));

    expect(StorageTables::texts(
        StorageTables::superuser(),
        "select relname || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where relname in ('slug_history', 'node_route_history') and relkind = 'r' order by relname",
    ))->toBe(['node_route_history true true', 'slug_history true true'])
        ->and(DB::connection()->table('slug_history')->count())->toBe(0)
        ->and(StorageTables::superuser()->table('slug_history')->count())->toBe(1);
});
