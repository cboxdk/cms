<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\UnknownConnection;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use InvalidArgumentException;

/**
 * The pins a fake receiver holds, one per connection.
 */
#[Experimental]
final readonly class SignalPins
{
    /** @var array<string, SignalPin> by connection */
    private array $pins;

    /**
     * @throws InvalidArgumentException when two pins name one connection
     */
    public function __construct(SignalPin ...$pins)
    {
        $byConnection = [];

        foreach ($pins as $pin) {
            if (isset($byConnection[$pin->connection->value])) {
                throw new InvalidArgumentException(sprintf('Two pins name the connection %s; a connection has one.', $pin->connection->value));
            }

            $byConnection[$pin->connection->value] = $pin;
        }

        $this->pins = $byConnection;
    }

    /**
     * @throws UnknownConnection
     */
    public function of(ConnectionId $connection): SignalPin
    {
        return $this->pins[$connection->value] ?? throw UnknownConnection::named($connection);
    }
}
