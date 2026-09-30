<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Workbench\App\Providers\WorkbenchServiceProvider;

/*
 * MILESTONES M1 point 5: publish, resolve and revise end to end (PRD 6.1, 8.3, 8.4). A fixture
 * article is created, placed below north's section with the slug "harbour" and published through
 * the real command pipeline on Postgres. GET /v1/resolve over HTTP resolves its path, and the
 * second request is served from the Valkey fragment. The article is then revised with wait level
 * origin while the critical lane's runner, a vendor/bin/testbench cms:events:run process of the
 * workbench, is running: the revise returns once the invalidation subscriber has purged the
 * fragment and acknowledged origin, so the receipt shows origin acknowledged and the next request
 * is resolved again. Without a runner, the same revise commits and returns committed_wait_timeout
 * when the wait budget has passed, with origin pending and the fragment still served.
 *
 * The world's clock is the system's time, because the runner reads the receipts on the system
 * clock; the delivery reads on the system clock too, after the publish. The runner shares this
 * checkout's test database and this run's Valkey database and key prefix through its environment,
 * runs as a service actor and purges the edge through the fake CDN driver
 * (WorkbenchServiceProvider::configureEventRunner()).
 */

const SKELETON_ENTRY = '0192a0c0-0000-7000-8000-0000000037e1';

const SKELETON_PLACEMENT = '0192a0c0-0000-7000-8000-0000000037c1';

/** The budget of the revise with a runner: the horizon is server-wide, so another checkout's suite can hold the runner back. */
const SKELETON_RUNNER_BUDGET_MS = 30_000;

/** The budget of the revise without a runner. */
const SKELETON_TIMEOUT_BUDGET_MS = 600;

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
 * The structure, the registry cms:build compiles (so the receipts list origin), and the fixture
 * article published below north's section at the system's time, by a world whose calls wait at
 * most $budget milliseconds for their wait level.
 */
function skeletonPublished(int $budget): PublishingWorld
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    $structure = PlacementWorld::seed($now);
    expect(app(Kernel::class)->call('cms:build'))->toBe(0);

    $world = new PublishingWorld([$structure->north->root, $structure->south->root], now: $now, waitBudget: $budget);
    $entry = EntryId::fromString(SKELETON_ENTRY);
    $placement = PlacementId::fromString(SKELETON_PLACEMENT);

    foreach ([
        $world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article('The harbour opens'), $structure->northSection, 'skeleton-create'),
        $world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour', 'skeleton-place'),
        $world->publish($entry, 1, 1, $placement, 1, 'skeleton-publish'),
    ] as $result) {
        if ($result->outcome() !== Outcome::Committed) {
            throw new AssertionFailedError('The article was not published: '.implode('; ', array_map(static fn (CatalogError $error): string => $error->message, $result->errors)));
        }
    }

    return $world;
}

/**
 * entry.revise of the published article's shared variant, at the version the create and the
 * publish left it, with wait level origin; and how long the call took, in milliseconds.
 *
 * @return array{WriteResult, float}
 */
function skeletonRevise(PublishingWorld $world): array
{
    $started = hrtime(true);
    $result = $world->revise(EntryId::fromString(SKELETON_ENTRY), 2, EntryFields::article('The harbour opens at dawn'), 'skeleton-revise', WaitLevel::Origin);

    return [$result, (hrtime(true) - $started) / 1_000_000];
}

/**
 * GET /v1/resolve of the article's path on north, through the application's HTTP kernel.
 *
 * @return TestResponse<\Illuminate\Http\Response>
 */
function skeletonResolve(): TestResponse
{
    $request = Request::create('/v1/resolve?'.http_build_query(['site' => 'north.example', 'locale' => 'da', 'path' => '/nyheder/harbour']));

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

function skeletonChangeset(Receipt $receipt): ChangesetId
{
    return $receipt->changesetId ?? throw new AssertionFailedError('The revise did not commit.');
}

/**
 * The state of origin on the stored receipt of the changeset, as the receipt store reads it now.
 */
function skeletonOrigin(ChangesetId $changeset): ?ProjectionState
{
    foreach (app(ReceiptStore::class)->find($changeset)->projections ?? [] as $status) {
        if ($status->projection->value === InvalidateFragments::PROJECTION) {
            return $status->state;
        }
    }

    return null;
}

/**
 * The state of origin on the receipt a call returned.
 */
function skeletonReceiptOrigin(Receipt $receipt): ?ProjectionState
{
    foreach ($receipt->projections as $status) {
        if ($status->projection->value === InvalidateFragments::PROJECTION) {
            return $status->state;
        }
    }

    return null;
}

/**
 * A process of the critical lane's runner in the workbench, as a deploy starts one, pointed at this
 * test's database, Valkey and service actor.
 */
function skeletonRunner(string $serviceActor): Process
{
    $run = app(ValkeyRun::class);

    return new Process(
        [PHP_BINARY, '-d', 'allow_url_fopen=0', 'vendor/bin/testbench', 'cms:events:run'],
        Phpstan::root(),
        [
            'DB_DATABASE' => CheckoutDatabase::name(),
            'REDIS_DB' => (string) ValkeyRun::DATABASE,
            'REDIS_PREFIX' => $run->prefix,
            WorkbenchServiceProvider::SERVICE_ACTOR => $serviceActor,
            WorkbenchServiceProvider::CDN_DRIVER => 'fake',
        ],
        null,
        120,
    );
}

it('publishes an article, serves its path from the fragment, and revises it at wait level origin, which returns once the fragment is invalidated', function (): void {
    $world = skeletonPublished(SKELETON_RUNNER_BUDGET_MS);

    $first = skeletonResolve();
    $second = skeletonResolve();

    $first->assertOk()
        ->assertHeader(DeliveryOutput::SOURCE, 'miss')
        ->assertJsonPath('data.cms_id', SKELETON_ENTRY)
        ->assertJsonPath('data.fixture_title', 'The harbour opens')
        ->assertJsonPath('meta.canonical_url', 'https://north.example/nyheder/harbour');
    $second->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'hit');
    expect($second->getContent())->toBe($first->getContent());

    $service = PostgresIdentity::at($world->clock)->addActor(ActorClass::Service)->id->toString();
    $runner = skeletonRunner($service);
    $runner->start();

    try {
        [$revise, $took] = skeletonRevise($world);
    } finally {
        $runner->stop(0);
    }

    $changeset = skeletonChangeset($revise->receipt);
    $third = skeletonResolve();

    expect($revise->outcome())->toBe(Outcome::Committed, sprintf("The revise was not waited out in %d ms:\n%s\n%s", SKELETON_RUNNER_BUDGET_MS, $runner->getOutput(), $runner->getErrorOutput()))
        ->and($revise->receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and(skeletonReceiptOrigin($revise->receipt))->toBe(ProjectionState::Acknowledged)
        ->and(skeletonOrigin($changeset))->toBe(ProjectionState::Acknowledged)
        ->and($took)->toBeLessThan((float) SKELETON_RUNNER_BUDGET_MS);
    $third->assertOk()
        ->assertHeader(DeliveryOutput::SOURCE, 'miss')
        ->assertJsonPath('data.cms_id', SKELETON_ENTRY);
});

it('returns committed_wait_timeout within the budget for a revise at wait level origin with no runner, with the changeset committed', function (): void {
    $world = skeletonPublished(SKELETON_TIMEOUT_BUDGET_MS);
    skeletonResolve()->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'miss');

    [$revise, $took] = skeletonRevise($world);
    $changeset = skeletonChangeset($revise->receipt);

    expect($revise->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($revise->errors)->toBe([])
        ->and($revise->receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and($revise->receipt->position)->not->toBeNull()
        ->and(skeletonReceiptOrigin($revise->receipt))->toBe(ProjectionState::Pending)
        ->and(skeletonOrigin($changeset))->toBe(ProjectionState::Pending)
        ->and(StorageTables::superuser()->table('changesets')->where('changeset_id', $changeset->toString())->value('command'))->toBe('entry.revise')
        ->and($took)->toBeGreaterThanOrEqual((float) SKELETON_TIMEOUT_BUDGET_MS)
        ->and($took)->toBeLessThan((float) SKELETON_TIMEOUT_BUDGET_MS + 1500.0);
    skeletonResolve()->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'hit');
});
