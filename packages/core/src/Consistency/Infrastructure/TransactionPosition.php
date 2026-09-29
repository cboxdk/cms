<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Consistency\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * Reads the commit position of the caller's open transaction (PRD 7.4, 8.4): its xid8,
 * pg_current_xact_id(), which gives the transaction its xid when it has none yet. The changeset row
 * and the events the transaction writes carry the same value in their `xid` column, and its receipt
 * carries it as its position.
 *
 * It runs one statement on the caller's connection, the default connection unless one is named,
 * inside the caller's transaction, and never begins, commits or rolls back one (GUARDRAILS 4.1).
 * Without an open transaction it throws TransactionRequired before the statement: outside a
 * transaction the statement would run in a transaction of its own, whose xid no changeset carries.
 */
#[Experimental]
final readonly class TransactionPosition
{
    public const string CURRENT = 'select pg_current_xact_id()::text';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection, the one
     *                                   the command kernel opens its transaction on
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * @throws TransactionRequired when the connection has no transaction open
     */
    public function current(): CommitPosition
    {
        $db = $this->connections->connection($this->connection);

        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forPosition();
        }

        return $this->read($db);
    }

    private function read(ConnectionInterface $db): CommitPosition
    {
        $position = $db->scalar(self::CURRENT);

        try {
            return new CommitPosition(is_string($position) ? $position : '');
        } catch (InvalidReceipt $invalid) {
            throw new LogicException('Postgres gave the transaction no xid8 as text: '.$invalid->getMessage(), 0, $invalid);
        }
    }
}
