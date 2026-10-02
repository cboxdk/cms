<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use OutOfBoundsException;

/**
 * An IssuerResolver was asked about a connection it has no pin for. The connections come from the
 * environment's configuration, so this is a fault of the configuration or the caller, not of the
 * person who logs in.
 */
#[Experimental]
final class UnknownConnection extends OutOfBoundsException
{
    public static function named(ConnectionId $connection): self
    {
        return new self(sprintf('No issuer is pinned for the login connection %s.', $connection->value));
    }
}
