<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\UncommittableChangeset;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyAdded;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyId;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyRaised;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every ChangesetCommitter does (PRD 6.2 phase 7, invariants 11 and 37), held against the
 * fake the pipeline's action tests use and PostgresChangesetCommitter on real Postgres, with the
 * test-only tally aggregate: it refuses an empty plan and a plan that changes an aggregate the
 * command did not read, answers VersionConflict with every stale read and commits nothing, so an
 * aggregate read as absent is created once, lists on the receipt the projections of the events
 * the mutations give, and commits nothing when no partition covers the changeset.
 *
 * Each commit runs in a command transaction of its own, which commits a committed changeset and
 * rolls back everything else.
 */
trait ChangesetCommitterBehaviour
{
    /** The projection the harness's AffectedProjections lists for every tally.raised event. */
    public const string TALLY_PROJECTION = TallyWorld::PROJECTION;

    public const string FIRST_TALLY = '0192a0c0-0000-7000-8000-0000000000a1';

    public const string SECOND_TALLY = '0192a0c0-0000-7000-8000-0000000000a2';

    /**
     * The implementation under test, whose AffectedProjections lists TALLY_PROJECTION pending for
     * every TallyRaised and whose mutations of TallyAdded give a TallyRaised.
     */
    abstract protected function committer(): ChangesetCommitter;

    /**
     * The command transaction the commits run in, on the committer's connection.
     */
    abstract protected function commandTransaction(): CommandTransaction;

    /**
     * An active actor the changesets are committed by.
     */
    abstract protected function actor(): ActorId;

    /**
     * Takes the changeset's time out of the partitions the commit writes to.
     */
    abstract protected function uncover(): void;

    /**
     * Covers the changeset's time again.
     */
    abstract protected function cover(): void;

    #[Test]
    public function it_refuses_an_empty_plan(): void
    {
        $this->expectException(UncommittableChangeset::class);

        $this->commitIn(new Plan, new ReadVersions);
    }

    #[Test]
    public function it_refuses_a_plan_that_changes_an_aggregate_the_command_did_not_read(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('The plan changes the aggregate "tally:%s", which the command did not read', self::SECOND_TALLY));

        $this->commitIn(
            new Plan(new TallyAdded(self::first(), 1), new TallyAdded(self::second(), 1)),
            new ReadVersions(ReadVersion::absent(self::first())),
        );
    }

    #[Test]
    public function it_commits_an_aggregate_read_as_absent(): void
    {
        $outcome = $this->create(self::first());

        Assert::assertInstanceOf(Committed::class, $outcome);
        Assert::assertTrue($outcome->receipt->isCommitted());
        Assert::assertSame(WaitLevel::Commit, $outcome->receipt->waitLevel);
        Assert::assertSame(RetentionClass::Standard, $outcome->receipt->retentionClass);
    }

    #[Test]
    public function it_creates_an_aggregate_read_as_absent_once(): void
    {
        $this->create(self::first());

        $again = $this->create(self::first());

        Assert::assertInstanceOf(VersionConflict::class, $again);
        Assert::assertEquals([new StaleRead(self::first(), null, AggregateVersion::first())], $again->stale);
    }

    #[Test]
    public function it_answers_a_stale_read_with_a_version_conflict_and_commits_nothing(): void
    {
        $this->create(self::first());
        Assert::assertInstanceOf(Committed::class, $this->raise(self::first(), new AggregateVersion(1)));

        $stale = $this->raise(self::first(), new AggregateVersion(1));

        Assert::assertInstanceOf(VersionConflict::class, $stale);
        Assert::assertEquals([new StaleRead(self::first(), new AggregateVersion(1), new AggregateVersion(2))], $stale->stale);
        Assert::assertInstanceOf(Committed::class, $this->raise(self::first(), new AggregateVersion(2)));
    }

    #[Test]
    public function it_names_every_stale_read_sorted_by_aggregate_key(): void
    {
        $this->create(self::first());
        $this->create(self::second());

        $outcome = $this->commitIn(
            new Plan(new TallyAdded(self::second(), 1)),
            new ReadVersions(ReadVersion::absent(self::second()), ReadVersion::at(self::first(), new AggregateVersion(2))),
        );

        Assert::assertInstanceOf(VersionConflict::class, $outcome);
        Assert::assertEquals([
            new StaleRead(self::first(), new AggregateVersion(2), AggregateVersion::first()),
            new StaleRead(self::second(), null, AggregateVersion::first()),
        ], $outcome->stale);
    }

    #[Test]
    public function it_lists_the_projections_of_the_events_on_the_receipt(): void
    {
        $outcome = $this->commitIn(
            new Plan(new TallyAdded(self::first(), 1), new TallyAdded(self::second(), 1)),
            new ReadVersions(ReadVersion::absent(self::first()), ReadVersion::absent(self::second())),
        );

        Assert::assertInstanceOf(Committed::class, $outcome);
        Assert::assertEquals([ProjectionStatus::pending(new ProjectionName(self::TALLY_PROJECTION))], $outcome->receipt->projections);
    }

    #[Test]
    public function it_commits_nothing_when_no_partition_covers_the_changeset(): void
    {
        $this->uncover();

        try {
            $this->create(self::first());
            Assert::fail('A commit no partition covers committed.');
        } catch (PartitionMissing $exception) {
            Assert::assertNotSame('', $exception->table);
        } finally {
            $this->cover();
        }

        Assert::assertInstanceOf(Committed::class, $this->create(self::first()));
    }

    private function create(TallyId $tally): CommitOutcome
    {
        return $this->commitIn(new Plan(new TallyAdded($tally, 1)), new ReadVersions(ReadVersion::absent($tally)));
    }

    private function raise(TallyId $tally, AggregateVersion $read): CommitOutcome
    {
        return $this->commitIn(new Plan(new TallyAdded($tally, 1)), new ReadVersions(ReadVersion::at($tally, $read)));
    }

    /**
     * Commits the plan with the reads in a command transaction of its own, and returns what the
     * committer answered.
     */
    private function commitIn(Plan $plan, ReadVersions $reads): CommitOutcome
    {
        $access = new AccessContext(
            new ActorPrincipal($this->actor(), [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Internal,
        );
        $envelope = Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->actor(),
            new IdempotencyKey('committer-behaviour'),
            new CorrelationId('committer-behaviour'),
            new OnBehalfOf,
            new Provenance,
        );
        $changeset = new PendingChangeset(new CommandName('tally.add'), 1, new AddTally(self::first(), 1), $envelope, $access, $plan, $reads);
        $outcome = null;

        $this->commandTransaction()->run($access, function () use ($changeset, &$outcome): WriteResult {
            $outcome = $this->committer()->commit($changeset);

            return $outcome instanceof Committed
                ? WriteResult::committed($outcome->receipt)
                : WriteResult::rejected(
                    Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard),
                    new CatalogError(ErrorCode::VersionConflict, null, 'The behaviour test rolls back a commit that committed nothing.'),
                );
        });

        Assert::assertInstanceOf(CommitOutcome::class, $outcome);

        return $outcome;
    }

    private static function first(): TallyId
    {
        return TallyId::fromString(self::FIRST_TALLY);
    }

    private static function second(): TallyId
    {
        return TallyId::fromString(self::SECOND_TALLY);
    }

    /**
     * The events the tally's mutation gives at a version, as TallyWriter gives them.
     *
     * @return list<TallyRaised>
     */
    protected static function tallyEvents(TallyAdded $mutation, AggregateVersion $version): array
    {
        return [new TallyRaised($mutation->tally, $version->value)];
    }
}
