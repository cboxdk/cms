<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A place in the order of commits (PRD 7.4, 8.4, 8.12): a Postgres xid8, the 64-bit transaction id
 * with its epoch, written in decimal as Postgres writes it.
 *
 * A changeset's position is the xid8 of the transaction that committed it, pg_current_xact_id():
 * the changeset row (its `xid` column), each of its events and its receipt carry the same value.
 * A read's position is the xmin of its snapshot, pg_snapshot_xmin(pg_current_snapshot()): no
 * transaction below it is still running, so a read at position R saw every changeset whose
 * position is below R. It may have seen changesets at or above R too; below R is what is certain.
 * This is the event horizon of PRD 7.4: a subscriber reads the events below the same xmin, and a
 * purge fence (PRD 8.12) compares a fragment's read position with the position of a purge.
 *
 * Positions are compared by their numeric value. The value is kept as a decimal string, because
 * an xid8 is an unsigned 64-bit integer, larger than a PHP int can hold, and JSON readers lose
 * precision above 2^53. It has no leading zeros, so equal positions have equal strings.
 */
#[Experimental]
final readonly class CommitPosition
{
    /** The largest xid8, 2^64 - 1. */
    public const string MAX = '18446744073709551615';

    private const string PATTERN = '/\A(0|[1-9][0-9]{0,19})\z/';

    /**
     * @throws InvalidReceipt when $value is not an xid8 in decimal without leading zeros
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || $this->compareDigits($value, self::MAX) > 0) {
            throw InvalidReceipt::commitPosition($value);
        }
    }

    /**
     * Whether this position is lower than $other: for a changeset position and a read position,
     * whether the read certainly saw the changeset.
     */
    public function isBelow(self $other): bool
    {
        return $this->compareDigits($this->value, $other->value) < 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Whether a read at $read certainly saw the changeset at this position: whether this position
     * is below the read's.
     */
    public function seenBy(self $read): bool
    {
        return $this->isBelow($read);
    }

    /**
     * Compares two decimal strings without leading zeros by their numeric value.
     */
    private function compareDigits(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }
}
