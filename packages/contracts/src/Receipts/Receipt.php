<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Receipts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ConsistencyToken;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * The result of one call of a write (PRD 6.1, 8.4, GUARDRAILS 2.1): the outcome, the changeset,
 * its position, the wait level the caller asked for and the status of each affected projection.
 *
 * Committed and CommittedWaitTimeout receipts carry the ChangesetId and its commit position.
 * Rejected and DryRun receipts committed nothing, so they have no ChangesetId, position,
 * consistency token or projection statuses.
 *
 * A Receipt is never stored. The outcome and the wait level belong to one call: the receipt store
 * is written in the command transaction (PRD 6.2 phase 7), before any wait level past commit can
 * be reached, and a replay (PRD 6.1) asks for its own wait level. The store keeps a StoredReceipt,
 * the facts of the changeset, and the command kernel builds the Receipt of each call from it, the
 * wait level the call asked for and whether that level was reached within the deadline.
 *
 * The projections are sorted by name, so two receipts with the same statuses are equal whatever
 * order they were given in. A projection appears at most once.
 *
 * The position is the changeset's commit position (PRD 8.4, 8.12), the xid8 of its transaction
 * (see CommitPosition): a read whose snapshot xmin is above it saw the changeset. The consistency
 * token (PRD 8.5) is the WAL position of the commit, which the write path reads after the commit
 * and a client sends with a later read to a replica; a committed receipt may lack it when the call
 * did not read it. Its JSON form is receipt.v1.json, written and read only by the generated codec,
 * Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1 (GUARDRAILS 2.2).
 */
#[Experimental]
final readonly class Receipt
{
    /** @var list<ProjectionStatus> */
    public array $projections;

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public function __construct(
        public Outcome $outcome,
        public ?ChangesetId $changesetId,
        public WaitLevel $waitLevel,
        public RetentionClass $retentionClass,
        array $projections = [],
        public ?CommitPosition $position = null,
        public ?ConsistencyToken $consistencyToken = null,
    ) {
        if ($outcome->isCommitted() && ! $changesetId instanceof ChangesetId) {
            throw InvalidReceipt::missingChangeset($outcome);
        }

        if (! $outcome->isCommitted() && $changesetId instanceof ChangesetId) {
            throw InvalidReceipt::unexpectedChangeset($outcome);
        }

        if (! $outcome->isCommitted() && $projections !== []) {
            throw InvalidReceipt::unexpectedProjections($outcome);
        }

        if ($outcome->isCommitted() && ! $position instanceof CommitPosition) {
            throw InvalidReceipt::missingPosition($outcome);
        }

        if (! $outcome->isCommitted() && $position instanceof CommitPosition) {
            throw InvalidReceipt::unexpectedPosition($outcome);
        }

        if (! $outcome->isCommitted() && $consistencyToken instanceof ConsistencyToken) {
            throw InvalidReceipt::unexpectedConsistencyToken($outcome);
        }

        $this->projections = ProjectionStatus::listOf($projections);
    }

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public static function committed(
        ChangesetId $changesetId,
        WaitLevel $waitLevel,
        RetentionClass $retentionClass,
        CommitPosition $position,
        array $projections = [],
        ?ConsistencyToken $consistencyToken = null,
    ): self {
        return new self(Outcome::Committed, $changesetId, $waitLevel, $retentionClass, $projections, $position, $consistencyToken);
    }

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public static function committedWaitTimeout(
        ChangesetId $changesetId,
        WaitLevel $waitLevel,
        RetentionClass $retentionClass,
        CommitPosition $position,
        array $projections = [],
        ?ConsistencyToken $consistencyToken = null,
    ): self {
        return new self(Outcome::CommittedWaitTimeout, $changesetId, $waitLevel, $retentionClass, $projections, $position, $consistencyToken);
    }

    public static function rejected(WaitLevel $waitLevel, RetentionClass $retentionClass): self
    {
        return new self(Outcome::Rejected, null, $waitLevel, $retentionClass);
    }

    public static function dryRun(WaitLevel $waitLevel, RetentionClass $retentionClass): self
    {
        return new self(Outcome::DryRun, null, $waitLevel, $retentionClass);
    }

    /**
     * Whether a changeset was committed. Only these receipts are stored.
     */
    public function isCommitted(): bool
    {
        return $this->outcome->isCommitted();
    }
}
