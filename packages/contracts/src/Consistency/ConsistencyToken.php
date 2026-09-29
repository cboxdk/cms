<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where a write's commit is in the database's history (PRD 8.4, 8.5): the failover generation, the
 * timeline Postgres wrote the WAL on, and the WAL position of the commit. A client sends it with a
 * later read, and a replica answers only when it is on the same timeline and has replayed at least
 * that position; otherwise the read goes to the primary.
 *
 * The write path reads the position after the commit, because a position read inside the
 * transaction lies before the commit record.
 */
#[Experimental]
final readonly class ConsistencyToken
{
    /** The largest timeline id Postgres gives, an unsigned 32-bit integer. */
    public const int MAX_GENERATION = 4_294_967_295;

    /**
     * @throws InvalidReceipt when the generation is not a timeline id
     */
    public function __construct(
        public int $generation,
        public LogSequenceNumber $lsn,
    ) {
        if ($generation < 1 || $generation > self::MAX_GENERATION) {
            throw InvalidReceipt::generation($generation);
        }
    }

    public function equals(self $other): bool
    {
        return $this->generation === $other->generation && $this->lsn->equals($other->lsn);
    }
}
