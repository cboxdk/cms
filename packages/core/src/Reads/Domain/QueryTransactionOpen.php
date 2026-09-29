<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A read was started inside a transaction that was already open. A read runs in a transaction of
 * its own, so its actor context and its snapshot are its own and end with it (PRD 5.10, 6.2), and
 * nested transactions and savepoints are forbidden (PRD 4.2). Nothing was read.
 */
#[Internal]
final class QueryTransactionOpen extends LogicException
{
    public static function onConnection(string $connection): self
    {
        return new self(sprintf('The connection "%s" already has a transaction open. A read runs in a transaction of its own, so its actor context ends with it, and nested transactions and savepoints are forbidden (PRD 4.2); end the open transaction first.', $connection));
    }
}
