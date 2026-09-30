<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Subscriptions\Actions\RunLane;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\StopAfterRounds;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use DateInterval;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The invalidation subscriber on Postgres and Valkey (PRD 7.6, 8.4, 8.12, 9.4): a revise through
 * the real command pipeline commits a receipt that lists origin pending, and after the critical
 * lane's runner, built by the container with the compiled registry, has handled the event, the
 * fragment of the entry is gone from Valkey, its key is fenced at the revise's position, the edge
 * is purged through the CDN driver, and origin is acknowledged on the receipt. The CDN driver is
 * the testkit's fake, bound as an application binds its driver.
 */

beforeEach(function (): void {
    EntryWorld::seed();
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * The world, with the container's clock at the world's and the fake CDN configured, and the
 * critical lane's runner set to run as a new service actor.
 */
function invalidationWorld(): EntryWorld
{
    $world = new EntryWorld;
    app()->instance(Clock::class, $world->clock);
    config()->set('cbox-cms.contracts.'.CdnDriver::class, FakeCdnDriver::class);
    config()->set('cbox-cms.events.runner.service_actor', PostgresIdentity::at($world->clock)->addActor(ActorClass::Service)->id->toString());
    config()->set('cbox-cms.events.runner.idle_sleep_ms', 5);

    return $world;
}

/**
 * The status of origin on the stored receipt of the changeset.
 */
function invalidationOrigin(ChangesetId $changeset): ?ProjectionStatus
{
    foreach (app(ReceiptStore::class)->find($changeset)->projections ?? [] as $status) {
        if ($status->projection->value === InvalidateFragments::PROJECTION) {
            return $status;
        }
    }

    return null;
}

/**
 * Runs the critical lane until origin is acknowledged on the changeset's receipt, for at most 30
 * seconds: the transaction horizon is the oldest transaction open on the server, so another
 * checkout's suite can hold it back.
 */
function invalidationRunUntilOrigin(ChangesetId $changeset): void
{
    $deadline = microtime(true) + 30;

    do {
        app(RunLane::class)->run(new LaneRun(Lane::Critical, untilIdle: true), new StopAfterRounds);

        if (invalidationOrigin($changeset)?->state === ProjectionState::Acknowledged) {
            return;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new AssertionFailedError('The runner did not acknowledge origin in 30 seconds.');
}

/**
 * The position of a read now: the xmin of the current snapshot. A fragment built from it has not
 * seen a changeset that commits later.
 */
function invalidationReadPosition(): CommitPosition
{
    $row = DB::selectOne('select pg_snapshot_xmin(pg_current_snapshot())::text as position');

    return new CommitPosition(is_object($row) && is_string($row->position ?? null) ? $row->position : throw new AssertionFailedError('No read position.'));
}

function invalidationFragment(string $name, CommitPosition $builtAt, EntryWorld $world): Fragment
{
    return new Fragment(
        new FragmentKey($name),
        '<article>'.$name.'</article>',
        [DependencyKey::entry(EntryWorld::entry())],
        $builtAt,
        $world->clock->now()->add(new DateInterval('PT1H')),
    );
}

function invalidationChangeset(WriteResult $result): ChangesetId
{
    return $result->receipt->changesetId ?? throw new AssertionFailedError('The write did not commit.');
}

it('lists origin pending on a revise\'s receipt and acknowledges it once the runner has invalidated the fragment', function (): void {
    $world = invalidationWorld();
    $created = invalidationChangeset($world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, EntryFields::measurement()));
    invalidationRunUntilOrigin($created);

    $fragments = app(FragmentStore::class);
    $stale = invalidationReadPosition();
    expect($fragments->write(invalidationFragment('page:measurement', $stale, $world)))->toBeInstanceOf(FragmentStored::class);

    $revise = $world->revise(1, EntryFields::measurement('22.500'), 'revise-origin');
    $revised = invalidationChangeset($revise);

    expect($revise->receipt->projections)->toEqual([ProjectionStatus::pending(new ProjectionName('origin'))])
        ->and(invalidationOrigin($revised)?->state)->toBe(ProjectionState::Pending)
        ->and($fragments->read(new FragmentKey('page:measurement')))->not->toBeNull();

    $world->clock->advance(new DateInterval('PT2S'));
    invalidationRunUntilOrigin($revised);

    $acknowledged = invalidationOrigin($revised);
    $cdn = app(CdnDriver::class);

    expect($acknowledged?->acknowledgedAt)->toEqual($world->clock->now())
        ->and($fragments->read(new FragmentKey('page:measurement')))->toBeNull()
        ->and($fragments->fragmentsOf(DependencyKey::entry(EntryWorld::entry())))->toBe([])
        ->and($fragments->write(invalidationFragment('page:measurement', $stale, $world)))->toBeInstanceOf(FragmentFenced::class)
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith(DependencyKey::entry(EntryWorld::entry())) : null)->toBe(PurgeMode::Soft)
        ->and(app(SubscriptionLog::class)->cursor(new SubscriptionName(InvalidateFragments::NAME), EventStream::Interactive)->xid)->toBe((int) $revise->receipt->position?->value);
});

it('lets a fragment built after the revise committed into the store', function (): void {
    $world = invalidationWorld();
    invalidationChangeset($world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, EntryFields::measurement()));
    $revised = invalidationChangeset($world->revise(1, EntryFields::measurement('22.500'), 'revise-origin'));
    invalidationRunUntilOrigin($revised);

    expect(app(FragmentStore::class)->write(invalidationFragment('page:measurement', invalidationReadPosition(), $world)))->toBeInstanceOf(FragmentStored::class);
});
