<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReceiptStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use LogicException;

/**
 * The advisory lock key that serialises the stores of one changeset in Postgres.
 *
 * The primary key of `receipts` is (changeset_id, retention_class), because a key on a partitioned
 * table must hold the LIST partition key, so it cannot keep one receipt per changeset across the
 * retention classes. PostgresReceiptStore::store() therefore takes this lock before it looks for a
 * receipt of the changeset: a second store waits until the first has committed, and its lookup, a
 * new statement under READ COMMITTED, sees the committed receipt. Inside a transaction the lock is
 * transaction-scoped; without one it is session-level and released after the inserts.
 *
 * The digest is the SHA-256 of a versioned JSON list: a fixed prefix and the changeset id. The lock
 * key is the first 64 bits of the digest as a signed bigint, the form pg_advisory_xact_lock(bigint)
 * and pg_advisory_lock(bigint) take. The prefix differs from the idempotency claims' (ClaimLock::VERSION), so a receipt and a
 * claim share a key only by a 64-bit collision, and a collision of any two keys only makes two
 * stores wait for each other. The encoding is fixed: changing it would let a store under the old
 * and the new key run at the same time during a deploy, so a new encoding needs a new prefix and a
 * plan for the switch.
 */
#[Internal]
final readonly class ReceiptLock
{
    /** The prefix of the encoded changeset; part of the lock key, so it never changes silently. */
    public const string VERSION = 'cbox_cms.receipt.v1';

    private function __construct(
        /** The SHA-256 of the encoded changeset, 64 lowercase hex digits. */
        public string $digest,
        /** The advisory lock key: the digest's first 64 bits as a signed integer. */
        public int $key,
    ) {}

    public static function of(ChangesetId $changesetId): self
    {
        $encoded = json_encode([self::VERSION, $changesetId->toString()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $encoded);
        $unpacked = unpack('J', (string) hex2bin(substr($digest, 0, 16)));
        $lockKey = is_array($unpacked) ? ($unpacked[1] ?? null) : null;

        if (! is_int($lockKey)) {
            throw new LogicException('Could not read the advisory lock key from the changeset digest.');
        }

        return new self($digest, $lockKey);
    }
}
