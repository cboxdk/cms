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
 * It reads `actors` through the query builder on the default connection, or the one named, and
 * always on the write PDO, so a read host that lags never hides a deactivation. It never writes
 * and never begins a transaction: inside the command transaction the read is part of it. The
 * table's row level security lets every role read, and the app role holds only SELECT; see the
 * migration.
 */
#[Experimental]
final readonly class PostgresActorDirectory implements ActorDirectory
{
    public const string TABLE = 'actors';

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
            ->table(self::TABLE.' as a')
            ->useWritePdo()
            ->where('a.id', $id->toString())
            ->first(IdentityRows::actorColumns('a'));

        return $row === null ? null : IdentityRows::actor($row, self::TABLE);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
