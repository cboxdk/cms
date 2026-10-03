<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads the version of actors' sets of grants (ActorGrantsRef, PRD 5.10) in one statement, as the
 * owner role, under the actor context of the caller's transaction: cms_access_actor_grants_versions
 * for a read, or cms_access_lock_actor_grants for the commit, which first takes the advisory lock on
 * each set. An actor without grants has version 1.
 */
#[Internal]
final readonly class ActorGrantVersions
{
    /** The versions of the sets, as the owner role. */
    public const string READ = 'select actor_id::text as actor_id, version from cms_access_actor_grants_versions(?::uuid[])';

    /** The versions of the sets after the lock on each, as the owner role. */
    public const string LOCK = 'select actor_id::text as actor_id, version from cms_access_lock_actor_grants(?::uuid[])';

    /**
     * The version of each actor's set by the actor's id.
     *
     * @param  list<ActorId>  $actors
     * @return array<string, AggregateVersion>
     */
    public static function of(ConnectionInterface $db, array $actors, bool $lock = false): array
    {
        if ($actors === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn (ActorId $actor): string => $actor->toString(), $actors)));
        $versions = [];

        foreach ($db->select($lock ? self::LOCK : self::READ, ['{'.implode(',', $ids).'}'], false) as $row) {
            $row = GrantRows::row($row);
            $versions[GrantRows::text($row, 'actor_id')] = new AggregateVersion(GrantRows::integer($row, 'version'));
        }

        return $versions;
    }
}
