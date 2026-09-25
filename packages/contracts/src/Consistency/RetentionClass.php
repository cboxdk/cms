<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * How long a stored receipt is kept (PRD 8.4, and the receipts row in PRD 4).
 */
#[Experimental]
enum RetentionClass: string
{
    /**
     * Receipts that are evidence, such as withdrawal, redaction, erasure and release bundles.
     * They are kept by policy, not by a fixed period.
     */
    case Evidence = 'evidence';

    /** Every other receipt, kept for STANDARD_DAYS. */
    case Standard = 'standard';

    public const int STANDARD_DAYS = 7;

    /**
     * The fixed retention in days, or null when a policy decides.
     */
    public function days(): ?int
    {
        return match ($this) {
            self::Evidence => null,
            self::Standard => self::STANDARD_DAYS,
        };
    }

    /**
     * The instant a receipt of this class for the changeset expires: the unix milliseconds in the
     * ChangesetId plus days(), in UTC. Null when a policy decides. The receipt is live up to and
     * including this instant, and expired once the Clock is later.
     */
    public function expiresAt(ChangesetId $changesetId): ?DateTimeImmutable
    {
        $days = $this->days();

        if ($days === null) {
            return null;
        }

        $milliseconds = $changesetId->unixMilliseconds() + $days * 86_400_000;
        $timestamp = sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000);

        return new DateTimeImmutable($timestamp)->setTimezone(new DateTimeZone('UTC'));
    }
}
