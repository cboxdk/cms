<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use SensitiveParameter;

/**
 * A login a connection has started and not completed (PRD 5.16): the connection, its flow, its
 * state, and the values the connection needs to complete it, such as the nonce and the PKCE code
 * verifier of OpenID Connect, by name.
 *
 * The caller keeps it server-side, in the session of the browser that started the login, never in
 * the browser, and removes it before it calls LoginConnection::complete(), so a pending login is
 * completed at most once. secrets() are never shown by var_dump() or in a stack trace.
 *
 * check() decides whether a response belongs to it: the pending login must be the connection's,
 * the response of its flow, and the response's state its state, compared in constant time.
 * Otherwise the login is refused with login_state_mismatch, before any credential or token is
 * looked at. Every connection checks through it.
 */
#[Experimental]
final readonly class PendingLogin
{
    /**
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        public ConnectionId $connection,
        public LoginFlow $flow,
        public LoginState $state,
        #[SensitiveParameter] private array $secrets = [],
    ) {}

    /**
     * @return array<string, string>
     */
    public function secrets(): array
    {
        return $this->secrets;
    }

    /**
     * The secret of the name, which the connection put there when it started the login.
     *
     * @throws InvalidIdentity when the pending login has no such secret
     */
    public function secret(string $name): string
    {
        return $this->secrets[$name] ?? throw InvalidIdentity::loginValue('pending login', 'one that holds every secret its connection made when it started the login');
    }

    /**
     * @throws LoginRefused when the response does not belong to this pending login of the connection
     */
    public function check(ConnectionId $connection, LoginResponse $response): void
    {
        if (! $this->connection->equals($connection)
            || $response->flow() !== $this->flow
            || ! $this->state->matches($response->state())) {
            throw LoginRefused::because(LoginErrorCode::StateMismatch);
        }
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function __debugInfo(): array
    {
        return [
            'connection' => $this->connection->value,
            'flow' => $this->flow->value,
            'secrets' => array_map(strval(...), array_keys($this->secrets)),
            'state' => '[hidden]',
        ];
    }
}
