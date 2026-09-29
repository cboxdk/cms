<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Ids\ActorId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The actor directory on Postgres (PRD 5.16, 6.2), as the app role.
 *
 * It reads one row of `actors` by its id through the lookup function `cms_identity_actor`, on the
 * default connection, or the one named, and always on the write PDO, so a read host that lags never
 * hides a deactivation. It never writes and never begins a transaction: inside the command
 * transaction the read is part of it. The table's row level security gives the app role no row
 * without an actor context, and the directory runs before one exists (PRD 6.2), so the lookup runs
 * as the owner role and returns the one actor asked for, never a list; see the access migration.
 */
#[Experimental]
final readonly class PostgresActorDirectory implements ActorDirectory
{
    public const string TABLE = 'actors';

    /** The lookup of one actor by its id, as the owner role (see the access migration). */
    public const string LOOKUP = 'cms_identity_actor';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    public function find(ActorId $id): ?Actor
    {
        $row = $this->db()
            ->table(self::TABLE)
            ->fromRaw(self::LOOKUP.'(?) as a', [$id->toString()])
            ->useWritePdo()
            ->first(IdentityRows::actorColumns('a'));

        return $row === null ? null : IdentityRows::actor($row, self::TABLE);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
