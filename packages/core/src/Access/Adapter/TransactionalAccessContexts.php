<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;

/**
 * AccessContexts on a database connection (PRD 5.10, 6.2): the default connection, or a named one.
 * It begins a transaction, resolves the principal with the PostgresAccessResolver, which reads the
 * actor's grants as the app role under the actor's own context, and rolls the transaction back,
 * because it wrote nothing and the context it set must end with it. A connection that is already
 * in a transaction is refused, because the transaction would become a savepoint of the caller's.
 */
#[Internal]
final readonly class TransactionalAccessContexts implements AccessContexts
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private AccessCompiler $compiler,
        private ?string $connection = null,
    ) {}

    /**
     * @throws LogicException when the connection is already in a transaction
     */
    #[Override]
    public function for(Principal $principal): AccessContext
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $connection = $this->connections->connection($name);

        if ($connection->transactionLevel() > 0) {
            throw new LogicException(sprintf('The access context is resolved in a transaction of its own, but the connection "%s" is already in a transaction.', $name));
        }

        $connection->beginTransaction();

        try {
            return new PostgresAccessResolver($this->connections, $this->compiler, $name)->resolve($principal);
        } finally {
            $connection->rollBack();
        }
    }
}
