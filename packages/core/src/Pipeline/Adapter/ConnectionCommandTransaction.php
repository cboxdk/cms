<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Throwable;

/**
 * The command transaction on a database connection (PRD 6.2 phase 7): the default connection, or a
 * named one, which the idempotency store, the receipt store and the ChangesetCommitter write on.
 *
 * It begins the transaction and runs three statements before the work: READ_COMMITTED as the
 * transaction's first statement, whatever the connection's default_transaction_isolation says;
 * TIMEOUT, which turns transaction_timeout off and on again at TIMEOUT_MILLISECONDS, because
 * Postgres starts the timer anew only when the setting goes from off to on, so the limit counts
 * from here whatever the role's setting was; and the call's access context, through ActorContext,
 * with SET LOCAL. While the work runs, the SavepointRefusal refuses a nested transaction or a
 * savepoint on the connection.
 *
 * A result that committed a changeset is committed; any other result is rolled back, and so is the
 * transaction of work that throws, before the exception goes on. A commit that fails is rolled back
 * on the connection too, so the connection is left without a transaction whatever happened. A
 * transaction that runs past its limit is ended by Postgres, which closes the session; the work's
 * next statement then throws.
 */
#[Internal]
final readonly class ConnectionCommandTransaction implements CommandTransaction
{
    /** The first statement of every command transaction. */
    public const string READ_COMMITTED = 'set transaction isolation level read committed';

    /** The time limit of the transaction, from this statement on, as SET LOCAL. */
    public const string TIMEOUT = "select set_config('transaction_timeout', '0', true), set_config('transaction_timeout', ?, true)";

    public function __construct(
        private ConnectionResolverInterface $connections,
        private SavepointRefusal $savepoints,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function run(AccessContext $access, Closure $work): WriteResult
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $connection = $this->connections->connection($name);

        if ($connection->transactionLevel() > 0) {
            throw CommandTransactionOpen::onConnection($name);
        }

        $connection->beginTransaction();

        try {
            $connection->statement(self::READ_COMMITTED);
            $connection->statement(self::TIMEOUT, [(string) self::TIMEOUT_MILLISECONDS]);
            new ActorContext($this->connections, $name)->set($access);
            $result = $this->guarded($connection, $work);
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

    /**
     * Runs the work with savepoints refused on the connection.
     *
     * @param  Closure(): WriteResult  $work
     */
    private function guarded(ConnectionInterface $connection, Closure $work): WriteResult
    {
        if (! $connection instanceof Connection) {
            return $work();
        }

        $this->savepoints->enter($connection);

        try {
            return $work();
        } finally {
            $this->savepoints->leave($connection);
        }
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
