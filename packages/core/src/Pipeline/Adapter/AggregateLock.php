<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use LogicException;

/**
 * The advisory lock key of an aggregate that a command read as absent, such as the entry a create
 * command makes (PRD 6.2 phase 7, invariant 11). An absent aggregate has no row to lock, so the
 * commit takes this transaction-scoped lock before it checks that the aggregate is still absent:
 * two commits that create the same aggregate run one after the other, and the second, a new
 * statement under READ COMMITTED, finds the first one's row and ends in a version conflict instead
 * of failing on a unique key.
 *
 * The digest is the SHA-256 of a versioned JSON list: a fixed prefix and the aggregate key. The lock
 * key is the first 64 bits of the digest as a signed bigint, the form pg_advisory_xact_lock(bigint)
 * takes. The prefix differs from the receipts' and the idempotency claims', so two of these keys
 * meet only by a 64-bit collision, which only makes two commits wait for each other. The encoding
 * is fixed; a new encoding needs a new prefix.
 */
#[Internal]
final readonly class AggregateLock
{
    /** The prefix of the encoded aggregate; part of the lock key, so it never changes silently. */
    public const string VERSION = 'cbox_cms.aggregate.v1';

    private function __construct(
        /** The advisory lock key: the digest's first 64 bits as a signed integer. */
        public int $key,
    ) {}

    public static function of(AggregateRef $aggregate): self
    {
        $encoded = json_encode([self::VERSION, $aggregate->aggregateKey()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $unpacked = unpack('J', (string) hex2bin(substr(hash('sha256', $encoded), 0, 16)));
        $lockKey = is_array($unpacked) ? ($unpacked[1] ?? null) : null;

        if (! is_int($lockKey)) {
            throw new LogicException('Could not read the advisory lock key from the aggregate digest.');
        }

        return new self($lockKey);
    }
}
