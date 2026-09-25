<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Receipts;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The status of one projection in a receipt (PRD 8.4). An acknowledged projection has the time it
 * acknowledged, in UTC with microseconds. A pending projection has none.
 */
#[Experimental]
final readonly class ProjectionStatus
{
    /** 9999-12-31T23:59:59Z, the last second with a four-digit year. */
    private const int LAST_SECOND = 253_402_300_799;

    /** The acknowledgement time in UTC, or null while the projection is pending. */
    public ?DateTimeImmutable $acknowledgedAt;

    public function __construct(
        public ProjectionName $projection,
        public ProjectionState $state,
        ?DateTimeImmutable $acknowledgedAt = null,
    ) {
        if ($state === ProjectionState::Acknowledged && ! $acknowledgedAt instanceof DateTimeImmutable) {
            throw InvalidReceipt::missingAcknowledgement($projection);
        }

        if ($state === ProjectionState::Pending && $acknowledgedAt instanceof DateTimeImmutable) {
            throw InvalidReceipt::unexpectedAcknowledgement($projection);
        }

        $utc = $acknowledgedAt?->setTimezone(new DateTimeZone('UTC'));

        if ($utc instanceof DateTimeImmutable && ($utc->getTimestamp() < 0 || $utc->getTimestamp() > self::LAST_SECOND)) {
            throw InvalidReceipt::acknowledgedOutOfRange($projection, $utc);
        }

        $this->acknowledgedAt = $utc;
    }

    public static function pending(ProjectionName $projection): self
    {
        return new self($projection, ProjectionState::Pending);
    }

    public static function acknowledged(ProjectionName $projection, DateTimeImmutable $at): self
    {
        return new self($projection, ProjectionState::Acknowledged, $at);
    }
}
