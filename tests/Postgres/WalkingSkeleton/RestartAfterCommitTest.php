<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Tests\Support\CheckoutDatabase;
use Cbox\Cms\Tests\Support\Phpstan;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Workbench\App\Providers\WorkbenchServiceProvider;

/*
 * MILESTONES M1: a restart after commit still leads to invalidation (PRD 7.4, 7.6, 8.4). A revise
 * of a fixture_measurement commits through the real command pipeline while the critical lane's
 * runner, a vendor/bin/testbench cms:events:run process of the workbench, is running but cannot
 * handle its event yet: a transaction that began before the revise holds the transaction horizon
 * back, so the event is not below it. The runner is killed with SIGKILL, as a crash or a deploy
 * would stop it, before it has handled the event. A new runner process then handles it: the
 * fragment of the entry that a read built before the revise is gone from Valkey, the entry's key
 * is fenced against that read's position, and origin is acknowledged on the revise's receipt.
 *
 * The world's clock is the system's time, because the runner processes read the receipts on the
 * system clock. They share this checkout's test database and this run's Valkey database and key
 * prefix through their environment, run as a service actor and purge the edge through the fake CDN
 * driver (WorkbenchServiceProvider::configureEventRunner()).
 */

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
    EntryWorld::cleanUp();
});

/**
 * A process of the critical lane's runner in the workbench, as a deploy starts one, with the
 * environment that points it at this test's database, Valkey and service actor.
 *
 * @param  list<string>  $options
 */
function runnerProcess(string $serviceActor, array $options = []): Process
{
    $run = app(ValkeyRun::class);

    return new Process(
        [PHP_BINARY, '-d', 'allow_url_fopen=0', 'vendor/bin/testbench', 'cms:events:run', ...$options],
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

function restartOrigin(ChangesetId $changeset): ?ProjectionState
{
    foreach (app(ReceiptStore::class)->find($changeset)->projections ?? [] as $status) {
        if ($status->projection->value === InvalidateFragments::PROJECTION) {
            return $status->state;
        }
    }

    return null;
}

/**
 * Waits up to 30 seconds for the runner to acknowledge origin on the changeset's receipt. The
 * horizon is the oldest transaction open on the server, so another checkout's suite can hold it
 * back.
 */
function awaitOrigin(ChangesetId $changeset, Process $runner): void
{
    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline) {
        if (restartOrigin($changeset) === ProjectionState::Acknowledged) {
            return;
        }

        if (! $runner->isRunning()) {
            throw new AssertionFailedError(sprintf("The runner exited with %s before origin was acknowledged:\n%s\n%s", var_export($runner->getExitCode(), true), $runner->getOutput(), $runner->getErrorOutput()));
        }

        usleep(50_000);
    }

    $runner->stop(0);

    throw new AssertionFailedError(sprintf("The runner did not acknowledge origin in 30 seconds:\n%s\n%s", $runner->getOutput(), $runner->getErrorOutput()));
}

/**
 * Runs new runner processes with --until-idle until origin is acknowledged on the changeset's
 * receipt, for at most 30 seconds, and gives their exit codes.
 *
 * @return list<int|null>
 */
function restartUntilOrigin(ChangesetId $changeset, string $serviceActor): array
{
    $deadline = microtime(true) + 30;
    $exits = [];

    do {
        $runner = runnerProcess($serviceActor, ['--until-idle']);
        $runner->run();
        $exits[] = $runner->getExitCode();

        if ($runner->getExitCode() !== 0) {
            throw new AssertionFailedError(sprintf("The runner exited with %s:\n%s\n%s", var_export($runner->getExitCode(), true), $runner->getOutput(), $runner->getErrorOutput()));
        }

        if (restartOrigin($changeset) === ProjectionState::Acknowledged) {
            return $exits;
        }
    } while (microtime(true) < $deadline);

    throw new AssertionFailedError('The runner processes did not acknowledge origin in 30 seconds.');
}

/**
 * The position of a read now: the xmin of the current snapshot.
 */
function restartReadPosition(): CommitPosition
{
    $row = DB::selectOne('select pg_snapshot_xmin(pg_current_snapshot())::text as position');

    return new CommitPosition(is_object($row) && is_string($row->position ?? null) ? $row->position : throw new AssertionFailedError('No read position.'));
}

function restartChangeset(WriteResult $result): ChangesetId
{
    return $result->receipt->changesetId ?? throw new AssertionFailedError('The write did not commit.');
}

it('invalidates the fragment of a revise that committed before the runner was killed, once a new runner process runs', function (): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    EntryWorld::seed($now);
    expect(app(Kernel::class)->call('cms:build'))->toBe(0);

    $world = new EntryWorld(now: $now);
    app()->instance(Clock::class, $world->clock);
    $service = PostgresIdentity::at($world->clock)->addActor(ActorClass::Service)->id->toString();
    $fragments = app(FragmentStore::class);
    $key = DependencyKey::entry(EntryWorld::entry());

    // The first runner handles the create.
    $first = runnerProcess($service);
    $first->start();
    $created = restartChangeset($world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, EntryFields::measurement()));
    awaitOrigin($created, $first);

    // A transaction with an id, open from before the revise until the first runner is gone, holds
    // the horizon below the revise, so the running runner cannot reach its event.
    [$horizon] = app(IndependentConnections::class)->open(1);
    $horizon->beginTransaction();
    $horizon->select('select pg_current_xact_id()');

    $stale = restartReadPosition();
    $fragment = new Fragment(new FragmentKey('page:restart'), '<p>21.125</p>', [$key], $stale, $world->clock->now()->add(new DateInterval('PT1H')));
    expect($fragments->write($fragment))->toBeInstanceOf(FragmentStored::class);

    $revise = $world->revise(1, EntryFields::measurement('22.500'), 'revise-restart');
    $revised = restartChangeset($revise);

    usleep(1_000_000);

    expect($first->isRunning())->toBeTrue()
        ->and(restartOrigin($revised))->toBe(ProjectionState::Pending)
        ->and($fragments->read(new FragmentKey('page:restart')))->not->toBeNull();

    // The runner stops before it has handled the event: killed, with no chance to finish a batch.
    $first->signal(9);
    $first->wait();
    $horizon->rollBack();

    expect($first->getTermSignal())->toBe(9)
        ->and(restartOrigin($revised))->toBe(ProjectionState::Pending)
        ->and($fragments->read(new FragmentKey('page:restart')))->not->toBeNull();

    // A new runner process picks the event up from the committed cursor and exits once the lane
    // is idle; it runs again only while another checkout's transaction holds the horizon back.
    $runs = restartUntilOrigin($revised, $service);

    expect($runs)->each->toBe(0)
        ->and($fragments->read(new FragmentKey('page:restart')))->toBeNull()
        ->and($fragments->fragmentsOf($key))->toBe([])
        ->and($fragments->write($fragment))->toBeInstanceOf(FragmentFenced::class)
        ->and(app(SubscriptionLog::class)->cursor(new SubscriptionName(InvalidateFragments::NAME), EventStream::Interactive)->xid)->toBe((int) $revise->receipt->position?->value);
});
