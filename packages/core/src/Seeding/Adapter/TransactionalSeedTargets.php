<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;
use UnexpectedValueException;

/**
 * SeedTargets on a database connection, the default or a named one: each read begins a
 * transaction, sets the actor's context and rolls the transaction back, because it wrote nothing and
 * the context must end with it. nodes() reads the ids of the nodes the context's regions reach
 * (cms_access_reaches) that are not mounts, sorted; existing() reads the entries through the
 * PostgresSeedReader, the reader seed.entries uses, so both see the same entries. A connection
 * already in a transaction is refused, because the transaction would become a savepoint of the
 * caller's.
 */
#[Internal]
final readonly class TransactionalSeedTargets implements SeedTargets
{
    /** A mount shows another node's placements and is never an entry's home (PRD 5.8). */
    public const string MOUNT = 'mount';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function nodes(AccessContext $access): array
    {
        $ids = $this->read($access, static fn (ConnectionInterface $db): array => $db->table('nodes')
            ->where('kind', '<>', self::MOUNT)
            ->whereRaw('cms_access_reaches(path)')
            ->orderBy('id')
            ->pluck('id')
            ->all());

        $nodes = [];

        foreach ($ids as $id) {
            $nodes[] = is_string($id) ? NodeId::fromString($id) : throw new UnexpectedValueException(sprintf('The id of a node is text, got %s.', get_debug_type($id)));
        }

        return $nodes;
    }

    #[Override]
    public function existing(AccessContext $access, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        return $this->read($access, fn (): array => new PostgresSeedReader($this->connections, $this->connection)->existing($entries));
    }

    /**
     * Runs the read in a transaction of its own under the context, rolled back after it.
     *
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $read
     * @return T
     */
    private function read(AccessContext $access, Closure $read): mixed
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $db = $this->connections->connection($name);

        if ($db->transactionLevel() > 0) {
            throw new LogicException(sprintf('The seed targets are read in a transaction of their own, but the connection "%s" is already in a transaction.', $name));
        }

        $db->beginTransaction();

        try {
            new ActorContext($this->connections, $name)->set($access);

            return $read($db);
        } finally {
            $db->rollBack();
        }
    }
}
