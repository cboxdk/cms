<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Results\QueryResult;
use Closure;

/**
 * The read transaction (PRD 5.10, 6.2, 8.4): the one transaction, on the primary, that a read runs
 * in from the verification of its credential to its answer. The query pipeline sets the actor
 * context in it with SET LOCAL, the action reads in it, and the read audit is written in it, so the
 * context ends with the read and a shared worker never carries it to the next one. The pipeline
 * never begins or ends it itself; this port does.
 *
 * run() begins the transaction with one snapshot for all of it, REPEATABLE READ, and calls the work
 * once. It commits an answered result, so the read audit written with it stays, and rolls back a
 * rejected one. When the work throws, it rolls back and throws the same exception. It never nests:
 * a caller that already has a transaction open gets QueryTransactionOpen, because the context and
 * the snapshot must be the read's own and savepoints are forbidden (PRD 4.2).
 *
 * position() is the read's position, the xmin of the transaction's snapshot (PRD 8.4, 8.12): the
 * read saw every changeset whose position is below it.
 */
#[Internal]
interface QueryTransaction
{
    /**
     * @param  Closure(): QueryResult  $work
     *
     * @throws QueryTransactionOpen when a transaction is already open on the connection
     */
    public function run(Closure $work): QueryResult;

    /**
     * @throws TransactionRequired when no read transaction is running
     */
    public function position(): CommitPosition;
}
