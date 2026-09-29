<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A command was started inside a transaction that was already open. A command runs in a
 * transaction of its own (PRD 6.2 phase 7), and nested transactions and savepoints are forbidden
 * (PRD 4.2), so the caller ends its transaction first. Nothing was run.
 */
#[Internal]
final class CommandTransactionOpen extends LogicException
{
    public static function onConnection(string $connection): self
    {
        return new self(sprintf('The connection "%s" already has a transaction open. A command runs in a transaction of its own, and nested transactions and savepoints are forbidden (PRD 4.2); end the open transaction first.', $connection));
    }
}
