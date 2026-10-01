<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\Reason;
use Cbox\Cms\Contracts\Envelope\ReasonCode;
use Cbox\Cms\Contracts\Envelope\ReasonText;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeAffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The real command pipeline on Postgres for the test-only command tally.add (PRD 6.1, 6.2 phase
 * 7): the container's command transaction, the Postgres idempotency and receipt stores, the actor
 * directory, and the PostgresChangesetCommitter with the actor's version lock, the tally's lock and
 * writer, and the projection PROJECTION pending for every tally.raised event. Fakes, so a test decides what they answer: the authorizer, which allows, the content hasher,
 * the type catalog and the hooks. The actor is an active staff member, seeded as the owner role.
 * The clock stands at NOW, whose day and the next the partitions cover, and the scratch table of
 * the tallies exists; cleanUp() drops it.
 */
final class TallyWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const string TALLY = '0192a0c0-0000-7000-8000-0000000000f1';

    public const string OTHER = '0192a0c0-0000-7000-8000-0000000000f2';

    public const string PROJECTION = 'tally.totals';

    /** @var list<string> the tables a commit writes, besides the actors a test seeds */
    public const array TABLES = [
        'changeset_register', 'changesets', 'changeset_principals', 'changeset_reason_texts', 'audit',
        'events', 'idempotency_keys', 'receipts', 'receipt_projections', TallyTable::TABLE,
    ];

    public readonly FakeClock $clock;

    public readonly PostgresIdentitySeeder $identity;

    public readonly ActorId $actor;

    public readonly TallyVersionLock $tallyLock;

    public ?TallyId $refuse = null;

    public int $eventVersionOffset = 0;

    /** The provenance of the calls add() makes. */
    public Provenance $provenance;

    /** @var (Closure(): void)|null */
    public ?Closure $meanwhile = null;

    private readonly FakeIdGenerator $ids;

    public function __construct()
    {
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->ids = new FakeIdGenerator(clock: $this->clock);
        $this->identity = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $this->clock, new FakeIdGenerator(seed: 7, clock: $this->clock));
        $this->tallyLock = new TallyVersionLock;
        $this->provenance = new Provenance;

        app(PartitionFixtures::class)->coverClock($this->clock, new DateInterval('P1D'));
        TallyTable::create();

        $this->actor = $this->identity->addActor(ActorClass::Staff)->id;
    }

    public static function cleanUp(): void
    {
        TallyTable::drop();
        DB::purge(StorageTables::SUPERUSER);
    }

    /**
     * The number of rows in each table a commit writes, read as the superuser, past row level
     * security.
     *
     * @return array<string, int>
     */
    public static function rows(): array
    {
        $superuser = StorageTables::superuser();
        $rows = [];

        foreach (self::TABLES as $table) {
            $rows[$table] = $superuser->table($table)->count();
        }

        return $rows;
    }

    /**
     * What rows() gives when nothing was committed.
     *
     * @return array<string, int>
     */
    public static function nothing(): array
    {
        return array_fill_keys(self::TABLES, 0);
    }

    public function committer(): PostgresChangesetCommitter
    {
        $connections = app(ConnectionResolverInterface::class);

        return new PostgresChangesetCommitter(
            $connections,
            $this->clock,
            $this->ids,
            new VersionLocks(new PostgresActorVersionLock($connections), $this->tallyLock),
            new MutationWriters(new TallyWriter($this->refuse, $this->eventVersionOffset)),
            new FakeAffectedProjections([TallyRaised::class => [new ProjectionName(self::PROJECTION)]]),
            new PostgresReceiptStore($connections, $this->clock),
        );
    }

    public static function tally(): TallyId
    {
        return TallyId::fromString(self::TALLY);
    }

    public static function other(): TallyId
    {
        return TallyId::fromString(self::OTHER);
    }

    public function pipeline(): CommandPipeline
    {
        $connections = app(ConnectionResolverInterface::class);
        $types = new FakeTypeCatalog;
        $receipts = new PostgresReceiptStore($connections, $this->clock);
        $action = new AddTallyAction(new TallyTable, $this->meanwhile);

        return new CommandPipeline(
            new FakeWriteActions([AddTally::class => $this->binding($action)]),
            app(ActorDirectory::class),
            new FakeCommandAuthorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            $this->committer(),
            new PostgresIdempotencyStore($connections, $this->clock),
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(200)),
            app(CommandTransaction::class),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );
    }

    /**
     * Runs tally.add through REST as the actor, with the key given.
     */
    public function add(AddTally $command, string $key = 'tally-1', ?Reason $reason = null, ActorId ...$onBehalfOf): WriteResult
    {
        return $this->pipeline()->run($this->call($command, Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->actor,
            new IdempotencyKey($key),
            new CorrelationId('tally-correlation'),
            new OnBehalfOf(...$onBehalfOf),
            $this->provenance,
            $reason,
        )));
    }

    /**
     * Runs tally.add as a seed, an internal issuer, for the unit of work given.
     */
    public function seed(AddTally $command, string $unit): WriteResult
    {
        return $this->pipeline()->run($this->call($command, Envelope::internal(
            IssuingSurface::Seed,
            EnvelopeIssuer::Seed,
            $this->actor,
            new UnitOfWork($unit),
            new CorrelationId('tally-seed'),
        )));
    }

    public static function reason(): Reason
    {
        return new Reason(new ReasonCode('correction'), new ReasonText('Asked by the desk.'));
    }

    /**
     * The binding of tally.add, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName('tally.add'), 1, $action);
    }

    private function call(AddTally $command, Envelope $envelope): CommandCall
    {
        return new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($envelope->actor, $envelope->onBehalfOf->chain, IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Internal,
        ));
    }
}
