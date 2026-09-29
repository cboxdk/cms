<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Throwable;

/**
 * The command transaction on a database connection (PRD 6.2 phase 7): the default connection, or a
 * named one, which the idempotency store, the receipt store and the ChangesetCommitter write on.
 *
 * It begins the transaction, sets READ COMMITTED as the transaction's first statement, whatever
 * the connection's default_transaction_isolation says, and runs the work. A result that committed a
 * changeset is committed; any other result is rolled back, and so is the transaction of work that
 * throws, before the exception goes on. A commit that fails is rolled back on the connection too,
 * so the connection is left without a transaction whatever happened.
 */
#[Internal]
final readonly class ConnectionCommandTransaction implements CommandTransaction
{
    /** The first statement of every command transaction. */
    public const string READ_COMMITTED = 'set transaction isolation level read committed';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function run(Closure $work): WriteResult
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $connection = $this->connections->connection($name);

        if ($connection->transactionLevel() > 0) {
            throw CommandTransactionOpen::onConnection($name);
        }

        $connection->beginTransaction();

        try {
            $connection->statement(self::READ_COMMITTED);
            $result = $work();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        if (! $result->receipt->isCommitted()) {
            $connection->rollBack();

            return $result;
        }

        $this->commit($connection);

        return $result;
    }

    private function commit(ConnectionInterface $connection): void
    {
        try {
            $connection->commit();
        } catch (Throwable $exception) {
            // A failed COMMIT ends the transaction on the server; this ends it on the connection.
            $connection->rollBack();

            throw $exception;
        }
    }
}
