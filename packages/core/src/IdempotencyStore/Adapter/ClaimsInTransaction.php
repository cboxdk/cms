<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\IdempotencyStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use LogicException;

/**
 * The claims the open transaction holds, as the Postgres store keeps them in a transaction-local
 * setting (set_config with is_local true). Postgres resets the setting when the transaction ends,
 * by commit or rollback, exactly when the advisory locks go, so the setting never outlives the
 * claims it describes.
 *
 * Each entry is `<claim digest>:<content hash>` for a Fresh claim that complete() may still record,
 * or `<claim digest>:completed` once it has. Entries are separated by commas. A claim that was
 * Replay or Conflict has no entry, so complete() refuses it.
 */
#[Internal]
final readonly class ClaimsInTransaction
{
    private const string COMPLETED = 'completed';

    private const string DIGEST = '/\A[0-9a-f]{64}\z/';

    /**
     * @param  array<string, string>  $entries  the content hash or `completed`, by claim digest
     */
    private function __construct(private array $entries) {}

    /**
     * Reads the setting's text; null and the empty string are no claims.
     */
    public static function parse(?string $setting): self
    {
        $entries = [];

        foreach (explode(',', $setting ?? '') as $entry) {
            if ($entry === '') {
                continue;
            }

            $parts = explode(':', $entry);

            if (count($parts) !== 2 || preg_match(self::DIGEST, $parts[0]) !== 1 || ($parts[1] !== self::COMPLETED && preg_match(self::DIGEST, $parts[1]) !== 1)) {
                throw new LogicException(sprintf('The transaction setting %s holds "%s", which the idempotency store never writes.', PostgresIdempotencyStore::CLAIMS_SETTING, $entry));
            }

            $entries[$parts[0]] = $parts[1];
        }

        return new self($entries);
    }

    public function withFresh(ClaimLock $lock, ContentHash $hash): self
    {
        return new self([...$this->entries, $lock->digest => $hash->value]);
    }

    public function withCompleted(ClaimLock $lock): self
    {
        return new self([...$this->entries, $lock->digest => self::COMPLETED]);
    }

    /**
     * Whether the transaction holds a Fresh claim on the lock's key with this content hash, not
     * completed yet.
     */
    public function isFresh(ClaimLock $lock, ContentHash $hash): bool
    {
        return ($this->entries[$lock->digest] ?? null) === $hash->value;
    }

    public function isCompleted(ClaimLock $lock): bool
    {
        return ($this->entries[$lock->digest] ?? null) === self::COMPLETED;
    }

    public function toSetting(): string
    {
        return implode(',', array_map(
            static fn (string $digest, string $state): string => $digest.':'.$state,
            array_keys($this->entries),
            $this->entries,
        ));
    }
}
