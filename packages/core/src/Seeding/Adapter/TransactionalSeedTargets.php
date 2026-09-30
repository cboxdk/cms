<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;
use UnexpectedValueException;

/**
 * SeedTargets on a database connection, the default or a named one: it begins a transaction, sets
 * the actor's context, reads the ids of the nodes the context's regions reach (cms_access_reaches)
 * that are not mounts, sorted, and rolls the transaction back, because it wrote nothing and the
 * context must end with it. A connection already in a transaction is refused, because the
 * transaction would become a savepoint of the caller's.
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
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $db = $this->connections->connection($name);

        if ($db->transactionLevel() > 0) {
            throw new LogicException(sprintf('The seed targets are read in a transaction of their own, but the connection "%s" is already in a transaction.', $name));
        }

        $db->beginTransaction();

        try {
            new ActorContext($this->connections, $name)->set($access);

            $ids = $db->table('nodes')
                ->where('kind', '<>', self::MOUNT)
                ->whereRaw('cms_access_reaches(path)')
                ->orderBy('id')
                ->pluck('id');
        } finally {
            $db->rollBack();
        }

        $nodes = [];

        foreach ($ids as $id) {
            $nodes[] = is_string($id) ? NodeId::fromString($id) : throw new UnexpectedValueException(sprintf('The id of a node is text, got %s.', get_debug_type($id)));
        }

        return $nodes;
    }
}
