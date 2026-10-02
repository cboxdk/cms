<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Locks the slot of a grant in the commit (PRD 6.2 phase 7, 5.10): the grant of the actor, role and
 * node that has not ended, FOR SHARE, through the owner function cms_access_lock_grant_slot. A slot
 * holds no version of its own, so a held slot is version 1 and a free one null. grant.assign reads
 * the slot as absent, so the commit takes its advisory lock before this, and a second assign of
 * the slot that waited for the first finds it held and ends in version_conflict.
 */
#[Internal]
final readonly class PostgresGrantSlotLock implements VersionLock
{
    /** The lock of a slot's grant, as the owner role. */
    public const string LOCK = 'select cms_access_lock_grant_slot(?::uuid, ?::uuid, ?::uuid) as held';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function kind(): string
    {
        return GrantSlotRef::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof GrantSlotRef) {
            throw new InvalidArgumentException(sprintf('The grant slot lock locks grant slots, not "%s".', $aggregate->aggregateKey()));
        }

        $held = $this->connections->connection($this->connection)->scalar(
            self::LOCK,
            [$aggregate->actor->toString(), $aggregate->role->toString(), $aggregate->node->toString()],
            false,
        );

        if (! is_bool($held)) {
            throw new UnexpectedValueException(sprintf('The lock of a grant slot says whether it is held, got %s.', get_debug_type($held)));
        }

        return $held ? AggregateVersion::first() : null;
    }
}
