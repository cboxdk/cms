<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;

/**
 * The VersionLock of each kind of aggregate (PRD 6.2 phase 7): the locks the core's service
 * provider finds under the container tag TAG, one per kind. The core registers the actor's; a
 * command task adds the locks of the aggregates its commands read.
 */
#[Internal]
final readonly class VersionLocks
{
    /** The container tag the locks are registered under. */
    public const string TAG = 'cbox-cms.version-locks';

    /** @var array<string, VersionLock> by kind */
    private array $locks;

    /**
     * @throws UncommittableChangeset when two locks lock the same kind
     */
    public function __construct(VersionLock ...$locks)
    {
        $byKind = [];

        foreach ($locks as $lock) {
            if (isset($byKind[$lock->kind()])) {
                throw UncommittableChangeset::duplicateLock($lock->kind());
            }

            $byKind[$lock->kind()] = $lock;
        }

        $this->locks = $byKind;
    }

    /**
     * The kind of an aggregate: its key up to the first colon.
     */
    public static function kindOf(AggregateRef $aggregate): string
    {
        return explode(':', $aggregate->aggregateKey(), 2)[0];
    }

    /**
     * @throws UncommittableChangeset when no lock locks the aggregate's kind
     */
    public function for(AggregateRef $aggregate): VersionLock
    {
        return $this->locks[self::kindOf($aggregate)] ?? throw UncommittableChangeset::noLock($aggregate->aggregateKey());
    }
}
