<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * Code running inside a command transaction began a nested transaction or issued a savepoint.
 * Savepoints and nested transactions are forbidden (PRD 4.2, GUARDRAILS 4.1): more than 64
 * subtransactions make every replica slow, and a command commits or rolls back as one. Nothing was
 * started; the command transaction rolls back and throws this.
 */
#[Internal]
final class SavepointRefused extends LogicException
{
    public static function nestedTransaction(string $connection): self
    {
        return new self(sprintf('A transaction was begun on the connection "%s" inside a command transaction, which Laravel would run as a SAVEPOINT. Savepoints and nested transactions are forbidden (PRD 4.2, GUARDRAILS 4.1); the command transaction is the only transaction of a command.', $connection));
    }

    public static function statement(string $connection, string $statement): self
    {
        return new self(sprintf('The statement "%s" was run on the connection "%s" inside a command transaction. Savepoints are forbidden (PRD 4.2, GUARDRAILS 4.1); the command transaction commits or rolls back as one.', $statement, $connection));
    }
}
