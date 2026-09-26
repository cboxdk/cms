<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\IdempotencyStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use LogicException;

/**
 * The name of a claim on (scope, key) and its advisory lock key in Postgres.
 *
 * The digest is the SHA-256 of a versioned JSON list: a fixed prefix, the principal kind, the
 * principal, the command type and the key. JSON quotes every part, so no two different claims
 * encode the same bytes. The lock key is the first 64 bits of the digest as a signed bigint, the
 * form pg_try_advisory_xact_lock(bigint) takes.
 *
 * Two different claims can share a lock key, with a chance of about n²/2⁶⁵ for n live keys. That
 * only serialises them: the second waits for the first transaction, or gets InFlight. It never
 * mixes their records, because every lookup also matches the full scope and key. The encoding is
 * fixed: changing it would let a claim under the old and the new key run at the same time during a
 * deploy, so a new encoding needs a new prefix and a plan for the switch.
 */
#[Internal]
final readonly class ClaimLock
{
    /** The prefix of the encoded claim; part of the lock key, so it never changes silently. */
    public const string VERSION = 'cbox_cms.idempotency.v1';

    private function __construct(
        /** The SHA-256 of the encoded claim, 64 lowercase hex digits. */
        public string $digest,
        /** The advisory lock key: the digest's first 64 bits as a signed integer. */
        public int $key,
    ) {}

    public static function of(IdempotencyScope $scope, IdempotencyKey $key): self
    {
        $encoded = json_encode(
            [self::VERSION, $scope->kind->value, $scope->principal->value, $scope->commandType->value, $key->value],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $digest = hash('sha256', $encoded);
        $unpacked = unpack('J', (string) hex2bin(substr($digest, 0, 16)));
        $lockKey = is_array($unpacked) ? ($unpacked[1] ?? null) : null;

        if (! is_int($lockKey)) {
            throw new LogicException('Could not read the advisory lock key from the claim digest.');
        }

        return new self($digest, $lockKey);
    }
}
