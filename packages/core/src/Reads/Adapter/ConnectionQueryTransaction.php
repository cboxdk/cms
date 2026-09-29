<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\QueryTransactionOpen;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;
use Throwable;

/**
 * The read transaction on a database connection (PRD 5.10, 6.2, 8.4): the default connection, or a
 * named one, which the access resolver, the read audit and the query actions' read ports use too.
 *
 * It begins the transaction and sets REPEATABLE READ as its first statement, whatever the
 * connection's default_transaction_isolation says, so every statement of the read sees one
 * snapshot, and the position read from it holds for all of them. Inside a transaction the framework
 * reads from the write connection, so the read runs on the primary. An answered result is
 * committed, a rejected one is rolled back, and so is the transaction of work that throws, before
 * the exception goes on; a commit that fails is rolled back on the connection too. The connection
 * is left without a transaction whatever happened, so the actor context set in it ends with it.
 */
#[Internal]
final readonly class ConnectionQueryTransaction implements QueryTransaction
{
    /** The first statement of every read transaction. */
    public const string REPEATABLE_READ = 'set transaction isolation level repeatable read';

    /** The read's position: the xmin of the transaction's snapshot. */
    public const string POSITION = 'select pg_snapshot_xmin(pg_current_snapshot())::text';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function run(Closure $work): QueryResult
    {
        $connection = $this->db();

        if ($connection->transactionLevel() > 0) {
            throw QueryTransactionOpen::onConnection($this->name());
        }

        $connection->beginTransaction();

        try {
            $connection->statement(self::REPEATABLE_READ);
            $result = $work();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        if (! $result->isAnswered()) {
            $connection->rollBack();

            return $result;
        }

        try {
            $connection->commit();
        } catch (Throwable $exception) {
            // A failed COMMIT ends the transaction on the server; this ends it on the connection.
            $connection->rollBack();

            throw $exception;
        }

        return $result;
    }

    #[Override]
    public function position(): CommitPosition
    {
        $connection = $this->db();

        if ($connection->transactionLevel() < 1) {
            throw TransactionRequired::forReadPosition();
        }

        $position = $connection->scalar(self::POSITION);

        try {
            return new CommitPosition(is_string($position) ? $position : '');
        } catch (InvalidReceipt $invalid) {
            throw new LogicException('Postgres gave the snapshot no xmin as text: '.$invalid->getMessage(), 0, $invalid);
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->name());
    }

    private function name(): string
    {
        return $this->connection ?? $this->connections->getDefaultConnection();
    }
}
