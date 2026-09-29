<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\SavepointRefused;
use Illuminate\Database\Connection;
use WeakMap;

/**
 * Refuses savepoints and nested transactions on a connection while a command transaction runs on
 * it (PRD 4.2, GUARDRAILS 4.1). The container holds one per process.
 *
 * The first time a connection enters, it registers two callbacks on it, once per connection
 * object: before a transaction begins, it throws SavepointRefused when the connection is inside a
 * command and a transaction is already open, which Laravel would run as a SAVEPOINT; and before a
 * statement runs, it throws when the connection is inside a command and the statement is a
 * SAVEPOINT, RELEASE or ROLLBACK TO. Both throw before anything reaches Postgres. Outside a command
 * the callbacks do nothing, so other code keeps Laravel's behaviour. The connections are held
 * weakly, so a connection the database manager purges is freed.
 */
#[Internal]
final class SavepointRefusal
{
    /** A statement that opens, releases or rolls back to a savepoint. */
    public const string SAVEPOINT = '/\A\s*(savepoint|release|rollback\s+to)\b/i';

    /** @var WeakMap<Connection, bool> whether each connection is inside a command */
    private WeakMap $commands;

    public function __construct()
    {
        $this->commands = new WeakMap;
    }

    /**
     * Marks the connection as inside a command until leave().
     */
    public function enter(Connection $connection): void
    {
        if (! isset($this->commands[$connection])) {
            $connection->beforeStartingTransaction(function (Connection $connection): void {
                if (($this->commands[$connection] ?? false) && $connection->transactionLevel() > 0) {
                    throw SavepointRefused::nestedTransaction((string) $connection->getName());
                }
            });

            $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
                if (($this->commands[$connection] ?? false) && preg_match(self::SAVEPOINT, $query) === 1) {
                    throw SavepointRefused::statement((string) $connection->getName(), $query);
                }
            });
        }

        $this->commands[$connection] = true;
    }

    public function leave(Connection $connection): void
    {
        $this->commands[$connection] = false;
    }
}
