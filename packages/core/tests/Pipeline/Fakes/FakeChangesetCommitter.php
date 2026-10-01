<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\UncommittableChangeset;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Closure;
use LogicException;
use Override;
use Throwable;

/**
 * Records every pending changeset it is handed, and throws what a test gives it, or answers with
 * the outcome a test gives it. Otherwise it commits as PostgresChangesetCommitter does, which
 * ChangesetCommitterBehaviour holds it to:
 *
 * - an empty plan throws UncommittableChangeset, and a plan that changes an aggregate the command
 *   did not read throws a LogicException;
 * - every read is compared with the version the fake knows the aggregate at: the versions a test
 *   states with at(), and the version each commit left an aggregate it changed at. A read that
 *   differs answers VersionConflict with every stale read and commits nothing. An aggregate the
 *   fake knows nothing of is taken at the version read, so an action test states the version of
 *   each aggregate its conflict is about;
 * - a commit leaves each aggregate it changes one above the version read, or at 1 when it was read
 *   as absent. Given the events of each mutation and AffectedProjections, the receipt lists the
 *   projections of those events, as the real commit lists them; otherwise it lists the
 *   projections a test gives.
 *
 * It commits as the changeset CHANGESET, or with the next id of the IdGenerator it is given. Given
 * a ReceiptStoreSession, it stores the changeset's StoredReceipt there, at the session's commit
 * position, as the real commit stores it in the command transaction, so a time the session's
 * store does not cover throws PartitionMissing and the fake commits nothing; without one, the
 * receipt carries the position POSITION.
 */
final class FakeChangesetCommitter implements ChangesetCommitter
{
    public const string CHANGESET = '01936f5e-8a2b-7c3d-9e4f-0000000000c5';

    /** The commit position of the receipt it answers with when it is given no ReceiptStoreSession. */
    public const string POSITION = '4827';

    /** @var list<PendingChangeset> */
    public array $pending = [];

    /** @var array<string, AggregateVersion|null> the versions it knows, by aggregate key */
    private array $versions = [];

    /**
     * @param  list<ProjectionStatus>  $projections  the projections of every receipt, when it is given no AffectedProjections
     * @param  (Closure(Mutation, AggregateVersion): list<Event>)|null  $events  the events a mutation gives at the version it leaves its aggregate at
     */
    public function __construct(
        private readonly ?CommitOutcome $outcome = null,
        private readonly ?ReceiptStoreSession $receipts = null,
        private readonly ?IdGenerator $ids = null,
        private readonly array $projections = [],
        private readonly ?Throwable $throws = null,
        private readonly ?AffectedProjections $affected = null,
        private readonly ?Closure $events = null,
    ) {}

    /**
     * States the version an aggregate is at now, or null when it is absent.
     */
    public function at(AggregateRef $aggregate, ?AggregateVersion $version): self
    {
        $this->versions[$aggregate->aggregateKey()] = $version;

        return $this;
    }

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        $this->pending[] = $changeset;

        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        if ($this->outcome instanceof CommitOutcome) {
            return $this->outcome;
        }

        $mutations = $changeset->plan->mutations();

        if ($mutations === []) {
            throw UncommittableChangeset::emptyPlan($changeset->command->value);
        }

        $stale = [];
        $next = [];

        foreach ($changeset->reads->reads as $read) {
            $key = $read->aggregate->aggregateKey();

            if (array_key_exists($key, $this->versions) && ! $this->sameVersion($read->version, $this->versions[$key])) {
                $stale[] = new StaleRead($read->aggregate, $read->version, $this->versions[$key]);
            }

            $next[$key] = $read->version instanceof AggregateVersion ? $read->version->next() : AggregateVersion::first();
        }

        if ($stale !== []) {
            return new VersionConflict(...$stale);
        }

        $changed = [];
        $events = [];

        foreach ($mutations as $mutation) {
            $key = $mutation->aggregate()->aggregateKey();
            $version = $next[$key] ?? throw new LogicException(sprintf('The plan changes the aggregate "%s", which the command did not read; the pipeline refuses such a plan before the commit.', $key));
            $changed[$key] = $version;

            if ($this->events instanceof Closure) {
                array_push($events, ...($this->events)($mutation, $version));
            }
        }

        $id = $this->ids instanceof IdGenerator
            ? new ChangesetId($this->ids->next())
            : ChangesetId::fromString(self::CHANGESET);

        $projections = $this->affected instanceof AffectedProjections ? $this->affected->pendingFor($events) : $this->projections;

        $position = $this->receipts instanceof ReceiptStoreSession
            ? $this->receipts->position()
            : new CommitPosition(self::POSITION);

        $this->receipts?->receipts()->store(new StoredReceipt($id, RetentionClass::Standard, $position, $projections));

        foreach ($changed as $key => $version) {
            $this->versions[$key] = $version;
        }

        return new Committed(Receipt::committed($id, $changeset->envelope->waitLevel, RetentionClass::Standard, $position, $projections));
    }

    private function sameVersion(?AggregateVersion $read, ?AggregateVersion $current): bool
    {
        return $read instanceof AggregateVersion && $current instanceof AggregateVersion
            ? $read->equals($current)
            : $read === $current;
    }
}
