<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\IssuerResolver;
use Cbox\Cms\Contracts\Identity\Login\TokenClaims;
use Cbox\Cms\Contracts\Identity\Login\UnknownConnection;
use InvalidArgumentException;
use Override;

/**
 * The in-memory fake of IssuerResolver (GUARDRAILS 2.3), and its own harness: it holds the pins
 * it is given, one per connection, and admits through IssuerPin::admit(), as every resolver does.
 * The real resolver, which reads the pins from the environment's configuration, comes with the
 * OpenID Connect implementation.
 */
#[Experimental]
final readonly class FakeIssuerResolver implements IssuerResolver, IssuerResolverHarness
{
    /** @var array<string, IssuerPin> by connection */
    private array $pins;

    /**
     * @throws InvalidArgumentException when two pins name one connection
     */
    public function __construct(IssuerPin ...$pins)
    {
        $byConnection = [];

        foreach ($pins as $pin) {
            if (isset($byConnection[$pin->connection->value])) {
                throw new InvalidArgumentException(sprintf('Two pins name the login connection %s; a connection has one.', $pin->connection->value));
            }

            $byConnection[$pin->connection->value] = $pin;
        }

        $this->pins = $byConnection;
    }

    #[Override]
    public function resolver(IssuerPin ...$pins): IssuerResolver
    {
        return new self(...$pins);
    }

    #[Override]
    public function pin(ConnectionId $connection): IssuerPin
    {
        return $this->pins[$connection->value] ?? throw UnknownConnection::named($connection);
    }

    #[Override]
    public function admit(ConnectionId $connection, TokenClaims $claims): IdpIdentity
    {
        return $this->pin($connection)->admit($claims);
    }
}
