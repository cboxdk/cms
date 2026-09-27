<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Receipts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * The result of one call of a write (PRD 6.1, 8.4, GUARDRAILS 2.1): the outcome, the changeset,
 * the wait level the caller asked for and the status of each affected projection.
 *
 * Committed and CommittedWaitTimeout receipts carry the ChangesetId. Rejected and DryRun receipts
 * committed nothing, so they have neither a ChangesetId nor projection statuses.
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
 * The position from PRD 8.4, the consistency token of PRD 8.5, arrives in M1 with the write path.
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

        $this->projections = ProjectionStatus::listOf($projections);
    }

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public static function committed(
        ChangesetId $changesetId,
        WaitLevel $waitLevel,
        RetentionClass $retentionClass,
        array $projections = [],
    ): self {
        return new self(Outcome::Committed, $changesetId, $waitLevel, $retentionClass, $projections);
    }

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public static function committedWaitTimeout(
        ChangesetId $changesetId,
        WaitLevel $waitLevel,
        RetentionClass $retentionClass,
        array $projections = [],
    ): self {
        return new self(Outcome::CommittedWaitTimeout, $changesetId, $waitLevel, $retentionClass, $projections);
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
