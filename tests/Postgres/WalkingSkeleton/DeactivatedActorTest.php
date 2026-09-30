<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Identity\Adapter\PostgresCredentialVerifier;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Identity\DeactivationWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyTable;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Cbox\Cms\Core\Tests\Postgres\QueryProbe\SeeEntries;
use Cbox\Cms\Core\Tests\Postgres\QueryProbe\SeeEntriesAction;
use Cbox\Cms\Core\Tests\Postgres\QueryProbe\SeenContext;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Postgres\ChildProcess;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\AssertionFailedError;

/*
 * PRD invariant 37 and 5.16, M1 point 3: once an actor is deactivated, no command commits as the
 * actor or on its behalf, not even one that was running when the deactivation committed, and no
 * read runs as the actor. The deactivation is the kernel's actor.deactivate through the real
 * pipeline on Postgres (DeactivationWorld); the actor's commands are the test-only tally.add through
 * the real pipeline too (TallyWorld), whose actor is the one deactivated; the read is the query
 * pipeline with the actor's service credential.
 *
 * The command in flight: tally.add has read its actor when a child process deactivates the actor
 * and holds its transaction open for HOLD_MS after the commit wrote everything, with the actor's
 * row locked. The command's commit then waits for that lock, so it is answered only after the child
 * let go, and once the deactivation commits it finds the actor at a newer version and fails with
 * version_conflict.
 */

/** How long the child holds the deactivation's transaction open after writing it. */
const HOLD_MS = 400;

afterEach(function (): void {
    TallyWorld::cleanUp();
});

/**
 * @return list<string>
 */
function codesOf(WriteResult|QueryResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

function deactivate(TallyWorld $world, ActorId $target): void
{
    $admin = $world->identity->addActor(ActorClass::Staff)->id;
    $result = DeactivationWorld::onDefault()->deactivate($admin, $target);

    if ($result->outcome() !== Outcome::Committed) {
        throw new AssertionFailedError('The deactivation did not commit: '.implode(', ', codesOf($result)));
    }
}

function tallyCommits(): int
{
    return StorageTables::superuser()->table('changesets')->where('command', 'tally.add')->count();
}

it('rejects a command as a deactivated actor', function (): void {
    $world = new TallyWorld;
    deactivate($world, $world->actor);

    $result = $world->add(new AddTally(TallyWorld::tally(), 2));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(codesOf($result))->toBe(['actor_not_active'])
        ->and($result->receipt->changesetId)->toBeNull()
        ->and(tallyCommits())->toBe(0)
        ->and(TallyTable::row(TallyWorld::tally()))->toBeNull();
});

it('rejects a command on behalf of a deactivated actor', function (): void {
    $world = new TallyWorld;
    $principal = $world->identity->addActor(ActorClass::Staff)->id;
    deactivate($world, $principal);

    $result = $world->add(new AddTally(TallyWorld::tally(), 2), 'on-behalf', null, $principal);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(codesOf($result))->toBe(['actor_not_active'])
        ->and(tallyCommits())->toBe(0)
        ->and(TallyTable::row(TallyWorld::tally()))->toBeNull();
});

it('fails a command in flight with version_conflict when a deactivation of its actor commits meanwhile', function (): void {
    $world = new TallyWorld;
    $admin = $world->identity->addActor(ActorClass::Staff)->id->toString();
    $target = $world->actor->toString();
    $hold = HOLD_MS;
    /** @var ChildProcess|null $child */
    $child = null;

    $world->meanwhile = static function () use (&$child, $admin, $target, $hold): void {
        $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($admin, $target, $hold): void {
            $world = DeactivationWorld::onConnection($context->connection(), 3131, static function () use ($context, $hold): void {
                $context->signal('deactivated');
                usleep($hold * 1000);
                $context->signal('releasing='.hrtime(true));
            });
            $result = $world->deactivate(ActorId::fromString($admin), ActorId::fromString($target), 'deactivate-in-flight');
            $context->signal('outcome='.$result->outcome()->value);
        });
        $child->waitForSignal('deactivated');
    };

    $result = $world->add(new AddTally(TallyWorld::tally(), 2), 'in-flight');
    $answered = hrtime(true);

    if (! $child instanceof ChildProcess) {
        throw new AssertionFailedError('The command did not start the deactivation while it was in flight.');
    }

    $child->wait();
    [$deactivated, $releasing, $outcome] = $child->signals() + [null, null, null];

    // hrtime() is the system's monotonic clock, the same in both processes: the command was
    // answered only after the deactivation let go of the actor's row.
    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(codesOf($result))->toBe(['version_conflict'])
        ->and([$deactivated, $outcome])->toBe(['deactivated', 'outcome=committed'])
        ->and($answered)->toBeGreaterThan((int) substr((string) $releasing, strlen('releasing=')))
        ->and(tallyCommits())->toBe(0)
        ->and(TallyTable::row(TallyWorld::tally()))->toBeNull()
        ->and(StorageTables::texts(StorageTables::superuser(), 'select concat_ws(\' \', state, version) as value from actors where id = ?', [$target]))
        ->toBe(['deactivated 2']);
});

it('rejects a read as a deactivated actor through the query pipeline', function (): void {
    $world = new TallyWorld;
    $service = $world->identity->addActor(ActorClass::Service)->id;
    $credential = $world->identity->issue(new ServiceCredentialSpec($service, IssuerKind::Service, ClassificationAccess::Internal, $world->clock->now()->add(new DateInterval('P1D'))));
    $seen = new SeenContext;
    $pipeline = new QueryPipeline(
        new FakeQueryActions([SeeEntries::class => ProbeQueryBinding::of(new SeeEntriesAction(app(DatabaseManager::class), $seen), 'probe.see_entries', 1)]),
        new PostgresCredentialVerifier(app(DatabaseManager::class), $world->clock),
        app(AccessResolver::class),
        new FakeQueryAuthorizer,
        new QuerySettings(new QueryCost(10), new QueryCost(10)),
        new ReadableFields(new FakeTypeCatalog),
        app(ReadAudit::class),
        app(QueryTransaction::class),
        new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
    );
    $before = $pipeline->run(new QueryCall(new SeeEntries, $credential));

    deactivate($world, $service);
    $after = $pipeline->run(new QueryCall(new SeeEntries, $credential));

    expect($before->isAnswered())->toBeTrue()
        ->and($after->isAnswered())->toBeFalse()
        ->and(codesOf($after))->toBe(['actor_not_active'])
        ->and(array_column($seen->reads, 'actor'))->toBe([$service->toString()]);
});
