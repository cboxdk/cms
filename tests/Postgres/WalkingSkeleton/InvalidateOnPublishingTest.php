<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryOutput;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Tests\Support\CheckoutDatabase;
use Cbox\Cms\Tests\Support\Phpstan;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Workbench\App\Providers\WorkbenchServiceProvider;

/*
 * The invalidation of what the public sees (PRD 8.4, 8.12, 9.4): a release, an unpublish, and a
 * placement and publication at a path that answered 404, each at wait level origin while a
 * critical lane runner process of the workbench runs. Each call returns once the invalidation
 * subscriber has acknowledged origin on its receipt, and the next GET /v1/resolve is then built
 * again from the read instead of served from the stored fragment: the released content, the 404
 * of the unpublished path, and the published article where a 404 was stored before.
 *
 * As in PublishResolveReviseTest, the world's clock is the system's time, because the runner and
 * the delivery read on the system clock, and the runner shares this checkout's test database, this
 * run's Valkey database and key prefix, runs as a service actor and purges the edge through the
 * fake CDN driver (WorkbenchServiceProvider::configureEventRunner()).
 */

const INVALIDATED_ENTRY = '0192a0c0-0000-7000-8000-0000000038e1';

const INVALIDATED_PLACEMENT = '0192a0c0-0000-7000-8000-0000000038c1';

/** The budget of a call at origin: the horizon is server-wide, so another checkout's suite can hold the runner back. */
const INVALIDATED_BUDGET_MS = 30_000;

beforeEach(function (): void {
    config()->set('cbox-cms.sites', [
        'north' => ['origin' => 'https://north.example'],
        'south' => ['origin' => 'https://south.example'],
    ]);
    config()->set('cbox-cms.delivery.max_age_seconds', 300);
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * The structure, the registry cms:build compiles (so the receipts list origin), and a world at the
 * system's time whose calls wait for their wait level within the budget.
 *
 * @return array{PublishingWorld, PlacementStructure}
 */
function invalidatedWorld(): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    $structure = PlacementWorld::seed($now);
    expect(app(Kernel::class)->call('cms:build'))->toBe(0);

    return [new PublishingWorld([$structure->north->root, $structure->south->root], now: $now, waitBudget: INVALIDATED_BUDGET_MS), $structure];
}

function invalidatedCommitted(WriteResult $result, string $what, ?Process $runner = null): WriteResult
{
    if ($result->outcome() !== Outcome::Committed) {
        throw new AssertionFailedError(sprintf(
            "The %s did not commit at its wait level: %s %s\n%s\n%s",
            $what,
            $result->outcome()->value,
            implode('; ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)),
            $runner?->getOutput() ?? '',
            $runner?->getErrorOutput() ?? '',
        ));
    }

    return $result;
}

/**
 * The fixture article, created below north's section and, unless told otherwise, placed there with
 * the slug "harbour" and published, all at wait level commit.
 */
function invalidatedArticle(PublishingWorld $world, PlacementStructure $structure, bool $published = true): void
{
    $entry = EntryId::fromString(INVALIDATED_ENTRY);
    $placement = PlacementId::fromString(INVALIDATED_PLACEMENT);

    invalidatedCommitted($world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article('The harbour opens'), $structure->northSection, 'invalidated-create'), 'create');

    if ($published) {
        invalidatedCommitted($world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour', 'invalidated-place'), 'placement');
        invalidatedCommitted($world->publish($entry, 1, 1, $placement, 1, 'invalidated-publish'), 'publish');
    }
}

/**
 * The command at wait level origin, and the state of origin on the receipt it returned.
 *
 * @return array{WriteResult, ?ProjectionState}
 */
function invalidatedAtOrigin(PublishingWorld $world, Command $command, string $key): array
{
    $result = $world->run($command, $key, waitLevel: WaitLevel::Origin);
    $origin = null;

    foreach ($result->receipt->projections as $status) {
        if ($status->projection->value === InvalidateFragments::PROJECTION) {
            $origin = $status->state;
        }
    }

    return [$result, $origin];
}

/**
 * GET /v1/resolve of the article's path on north, through the application's HTTP kernel.
 *
 * @return TestResponse<Response>
 */
function invalidatedResolve(): TestResponse
{
    $request = Request::create('/v1/resolve?'.http_build_query(['site' => 'north.example', 'locale' => 'da', 'path' => '/nyheder/harbour']));

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

/**
 * A process of the critical lane's runner in the workbench, pointed at this test's database,
 * Valkey and a new service actor.
 */
function invalidatedRunner(PublishingWorld $world): Process
{
    $run = app(ValkeyRun::class);
    $runner = new Process(
        [PHP_BINARY, '-d', 'allow_url_fopen=0', 'vendor/bin/testbench', 'cms:events:run'],
        Phpstan::root(),
        [
            'DB_DATABASE' => CheckoutDatabase::name(),
            'REDIS_DB' => (string) ValkeyRun::DATABASE,
            'REDIS_PREFIX' => $run->prefix,
            WorkbenchServiceProvider::SERVICE_ACTOR => PostgresIdentity::at($world->clock)->addActor(ActorClass::Service)->id->toString(),
            WorkbenchServiceProvider::CDN_DRIVER => 'fake',
        ],
        null,
        120,
    );
    $runner->start();

    return $runner;
}

it('releases a revised draft at wait level origin, which returns once the fragment of the old release is invalidated', function (): void {
    [$world, $structure] = invalidatedWorld();
    invalidatedArticle($world, $structure);
    $entry = EntryId::fromString(INVALIDATED_ENTRY);
    invalidatedCommitted($world->revise($entry, 2, EntryFields::article('The harbour opens at dawn'), 'invalidated-revise'), 'revise');

    invalidatedResolve()->assertOk()->assertJsonPath('data.fixture_title', 'The harbour opens');
    invalidatedResolve()->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'hit')->assertJsonPath('data.fixture_title', 'The harbour opens');

    $runner = invalidatedRunner($world);

    try {
        [$release, $origin] = invalidatedAtOrigin($world, new ReleaseVariant($entry, new RevisionNumber(3), new AggregateVersion(3)), 'invalidated-release');
    } finally {
        $runner->stop(0);
    }

    invalidatedCommitted($release, 'release', $runner);
    expect($origin)->toBe(ProjectionState::Acknowledged);
    invalidatedResolve()->assertOk()
        ->assertHeader(DeliveryOutput::SOURCE, 'miss')
        ->assertJsonPath('data.fixture_title', 'The harbour opens at dawn');
});

it('unpublishes at wait level origin, which returns once the fragment is invalidated, so the path answers 404 and is never served from the old fragment', function (): void {
    [$world, $structure] = invalidatedWorld();
    invalidatedArticle($world, $structure);

    invalidatedResolve()->assertOk();
    invalidatedResolve()->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'hit');

    $runner = invalidatedRunner($world);

    try {
        [$unpublish, $origin] = invalidatedAtOrigin($world, new UnpublishEntry(EntryId::fromString(INVALIDATED_ENTRY), new AggregateVersion(2)), 'invalidated-unpublish');
    } finally {
        $runner->stop(0);
    }

    invalidatedCommitted($unpublish, 'unpublish', $runner);
    expect($origin)->toBe(ProjectionState::Acknowledged);
    $gone = invalidatedResolve();
    $gone->assertNotFound()->assertHeader(DeliveryOutput::SOURCE, 'miss');
    expect($gone->headers->get('Cache-Control'))->not->toContain('stale-if-error')
        ->and($gone->headers->get('Cache-Control'))->not->toContain('stale-while-revalidate');
});

it('places and publishes at a path that answered 404 at wait level origin, which purges the stored 404 by its node, so the path resolves', function (): void {
    [$world, $structure] = invalidatedWorld();
    invalidatedArticle($world, $structure, published: false);

    invalidatedResolve()->assertNotFound();
    invalidatedResolve()->assertNotFound()->assertHeader(DeliveryOutput::SOURCE, 'hit');

    $entry = EntryId::fromString(INVALIDATED_ENTRY);
    $placement = PlacementId::fromString(INVALIDATED_PLACEMENT);
    $runner = invalidatedRunner($world);

    try {
        [$place, $placed] = invalidatedAtOrigin($world, new CreatePlacement($placement, $entry, $structure->northSection->id, $structure->north->id, [new LocaleSlug(new Locale('da'), new Slug('harbour'))]), 'invalidated-place');
        $hidden = invalidatedResolve();
        [$publish, $published] = invalidatedAtOrigin($world, new PublishEntry($entry, new AggregateVersion(1), new RevisionNumber(1), $placement, new AggregateVersion(1), new Locale('da')), 'invalidated-publish');
    } finally {
        $runner->stop(0);
    }

    invalidatedCommitted($place, 'placement', $runner);
    invalidatedCommitted($publish, 'publish', $runner);
    expect($placed)->toBe(ProjectionState::Acknowledged)
        ->and($published)->toBe(ProjectionState::Acknowledged);
    $hidden->assertNotFound()->assertHeader(DeliveryOutput::SOURCE, 'miss');
    invalidatedResolve()->assertOk()
        ->assertHeader(DeliveryOutput::SOURCE, 'miss')
        ->assertJsonPath('data.cms_id', INVALIDATED_ENTRY)
        ->assertJsonPath('data.fixture_title', 'The harbour opens');
});
