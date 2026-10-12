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
use Cbox\Cms\Contracts\Ids\NodeId;
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
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Structure\NodeWorld;
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
 * the testkit's fake, bound as an application binds its driver. A node moved with its subtree and
 * a node given a label are invalidated the same way, by the n- key of the node and, for a move,
 * of every node below it; a site.remove_locale purges the site root's node key the same way, hard
 * at the edge.
 */

const INVALIDATION_NODE = '0192a0c0-0000-7000-8000-0000000009a1';

const INVALIDATION_CHILD = '0192a0c0-0000-7000-8000-0000000009a2';

const INVALIDATION_TARGET = '0192a0c0-0000-7000-8000-0000000009a3';

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
 * A node world on the structure fixtures' sites, with the container's clock at the world's, the
 * fake CDN configured and the critical lane's runner set to run as a new service actor.
 */
function invalidationNodeWorld(PlacementStructure $structure): NodeWorld
{
    $world = new NodeWorld([$structure->north->root]);
    app()->instance(Clock::class, $world->clock);
    config()->set('cbox-cms.contracts.'.CdnDriver::class, FakeCdnDriver::class);
    config()->set('cbox-cms.events.runner.service_actor', PostgresIdentity::at($world->clock)->addActor(ActorClass::Service)->id->toString());
    config()->set('cbox-cms.events.runner.idle_sleep_ms', 5);

    return $world;
}

/**
 * A fragment that depends only on the node's key, as a breadcrumb or a 404 below it does, built at
 * the position and valid for an hour.
 */
function invalidationNodeFragment(string $name, string $node, CommitPosition $builtAt, NodeWorld $world): Fragment
{
    return new Fragment(
        new FragmentKey($name),
        '<nav>'.$name.'</nav>',
        [DependencyKey::node(NodeId::fromString($node))],
        $builtAt,
        $world->clock->now()->add(new DateInterval('PT1H')),
    );
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

it('purges the site root\'s node key when a locale of the site is removed, hard at the edge, and acknowledges origin', function (): void {
    $world = invalidationWorld();
    $structure = NodeWorld::seed();
    $nodes = new NodeWorld([$structure->north->root], seed: 5);
    $root = DependencyKey::node($structure->north->root->id);
    $fragments = app(FragmentStore::class);
    $stale = invalidationReadPosition();
    $fragment = new Fragment(new FragmentKey('path:north:en:/'), '<nav>en</nav>', [$root], $stale, $world->clock->now()->add(new DateInterval('PT1H')));
    expect($fragments->write($fragment))->toBeInstanceOf(FragmentStored::class);

    $removed = $nodes->removeLocale($structure->north, 'en', 'remove-en-origin');
    $changeset = invalidationChangeset($removed);

    expect($removed->receipt->projections)->toEqual([ProjectionStatus::pending(new ProjectionName('origin'))])
        ->and($fragments->read(new FragmentKey('path:north:en:/')))->not->toBeNull();

    $world->clock->advance(new DateInterval('PT2S'));
    invalidationRunUntilOrigin($changeset);
    $cdn = app(CdnDriver::class);

    expect(invalidationOrigin($changeset)?->state)->toBe(ProjectionState::Acknowledged)
        ->and($fragments->read(new FragmentKey('path:north:en:/')))->toBeNull()
        ->and($fragments->fragmentsOf($root))->toBe([])
        ->and($fragments->write($fragment))->toBeInstanceOf(FragmentFenced::class)
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith($root) : null)->toBe(PurgeMode::Hard);
});

it('lets a fragment built after the revise committed into the store', function (): void {
    $world = invalidationWorld();
    invalidationChangeset($world->create(EntryWorld::type(EntryWorld::MEASUREMENT)->id, EntryFields::measurement()));
    $revised = invalidationChangeset($world->revise(1, EntryFields::measurement('22.500'), 'revise-origin'));
    invalidationRunUntilOrigin($revised);

    expect(app(FragmentStore::class)->write(invalidationFragment('page:measurement', invalidationReadPosition(), $world)))->toBeInstanceOf(FragmentStored::class);
});

it('lists origin pending on a move\'s receipt and purges the fragments of the node and of every node below it once the runner has handled node.moved', function (): void {
    $structure = NodeWorld::seed();
    $world = invalidationNodeWorld($structure);
    $node = NodeId::fromString(INVALIDATION_NODE);
    $child = NodeId::fromString(INVALIDATION_CHILD);
    $target = NodeWorld::childOf($structure->north->root, NodeId::fromString(INVALIDATION_TARGET));
    $world->createNode($node, $structure->north->root, 'create-node');
    $world->createNode($child, NodeWorld::childOf($structure->north->root, $node), 'create-child');
    $world->createNode($target->id, $structure->north->root, 'create-target');

    $fragments = app(FragmentStore::class);
    $stale = invalidationReadPosition();
    expect($fragments->write(invalidationNodeFragment('crumbs:node', INVALIDATION_NODE, $stale, $world)))->toBeInstanceOf(FragmentStored::class)
        ->and($fragments->write(invalidationNodeFragment('crumbs:child', INVALIDATION_CHILD, $stale, $world)))->toBeInstanceOf(FragmentStored::class)
        ->and($fragments->write(invalidationNodeFragment('crumbs:target', INVALIDATION_TARGET, $stale, $world)))->toBeInstanceOf(FragmentStored::class);

    $move = $world->moveNode($node, $target, 'move-node');
    $moved = invalidationChangeset($move);

    expect($move->receipt->projections)->toEqual([ProjectionStatus::pending(new ProjectionName('origin'))])
        ->and($fragments->read(new FragmentKey('crumbs:child')))->not->toBeNull();

    $world->clock->advance(new DateInterval('PT2S'));
    invalidationRunUntilOrigin($moved);
    $cdn = app(CdnDriver::class);

    expect($fragments->read(new FragmentKey('crumbs:node')))->toBeNull()
        ->and($fragments->read(new FragmentKey('crumbs:child')))->toBeNull()
        ->and($fragments->read(new FragmentKey('crumbs:target')))->not->toBeNull()
        ->and($fragments->write(invalidationNodeFragment('crumbs:child', INVALIDATION_CHILD, $stale, $world)))->toBeInstanceOf(FragmentFenced::class)
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith(DependencyKey::node($node)) : null)->toBe(PurgeMode::Soft)
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith(DependencyKey::node($child)) : null)->toBe(PurgeMode::Soft)
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith(DependencyKey::node($target->id)) : null)->toBeNull()
        ->and(invalidationOrigin($moved)?->acknowledgedAt)->toEqual($world->clock->now());
});

it('purges the fragments of the node once the runner has handled node.label_changed', function (): void {
    $structure = NodeWorld::seed();
    $world = invalidationNodeWorld($structure);
    $node = NodeId::fromString(INVALIDATION_NODE);
    $world->createNode($node, $structure->north->root, 'create-node');

    $fragments = app(FragmentStore::class);
    $stale = invalidationReadPosition();
    expect($fragments->write(invalidationNodeFragment('crumbs:node', INVALIDATION_NODE, $stale, $world)))->toBeInstanceOf(FragmentStored::class)
        ->and($fragments->write(invalidationNodeFragment('crumbs:section', $structure->northSection->id->toString(), $stale, $world)))->toBeInstanceOf(FragmentStored::class);

    $labelled = invalidationChangeset($world->setLabel($node, 'Sport', 'label-node'));
    $world->clock->advance(new DateInterval('PT2S'));
    invalidationRunUntilOrigin($labelled);
    $cdn = app(CdnDriver::class);

    expect($fragments->read(new FragmentKey('crumbs:node')))->toBeNull()
        ->and($fragments->read(new FragmentKey('crumbs:section')))->not->toBeNull()
        ->and($fragments->write(invalidationNodeFragment('crumbs:node', INVALIDATION_NODE, $stale, $world)))->toBeInstanceOf(FragmentFenced::class)
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith(DependencyKey::node($node)) : null)->toBe(PurgeMode::Soft)
        ->and(invalidationOrigin($labelled)?->state)->toBe(ProjectionState::Acknowledged);
});
