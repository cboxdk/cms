<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;

/**
 * The login policy of one actor class in this environment (PRD 5.16, "Loginpolitik"):
 *
 * - $connections, the connections the class may log in through, each once;
 * - $methods, the login methods it may use, each once;
 * - $localLogin, whether local login is switched on; off, only federated connections give a session;
 * - $localFactors, the factors a local login requires;
 * - $federatedAmr and $federatedAcr, the MFA a federated login must show: one of the amr values or
 *   one of the acr values; both empty requires nothing;
 * - $lifetimes, the session's inactivity timeout and absolute lifetime.
 */
#[Internal]
final readonly class ClassPolicy
{
    /**
     * @param  list<ConnectionId>  $connections
     * @param  list<LoginMethod>  $methods
     * @param  list<AuthenticationMethod>  $federatedAmr
     * @param  list<AuthenticationContext>  $federatedAcr
     *
     * @throws InvalidLoginPolicy when a list names a value twice
     */
    public function __construct(
        public array $connections,
        public array $methods,
        public bool $localLogin,
        public LocalFactors $localFactors,
        public array $federatedAmr,
        public array $federatedAcr,
        public SessionLifetimes $lifetimes,
        string $key = 'the class policy',
    ) {
        $this->once($key.'.connections', array_map(static fn (ConnectionId $connection): string => $connection->value, $connections));
        $this->once($key.'.methods', array_map(static fn (LoginMethod $method): string => $method->value, $methods));
        $this->once($key.'.federated_amr', array_map(static fn (AuthenticationMethod $method): string => $method->value, $federatedAmr));
        $this->once($key.'.federated_acr', array_map(static fn (AuthenticationContext $context): string => $context->value, $federatedAcr));
    }

    public function allowsConnection(ConnectionId $connection): bool
    {
        return array_any($this->connections, static fn (ConnectionId $listed): bool => $listed->equals($connection));
    }

    public function allowsMethod(LoginMethod $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /**
     * @param  list<string>  $values
     *
     * @throws InvalidLoginPolicy
     */
    private function once(string $key, array $values): void
    {
        if (count(array_unique($values)) !== count($values)) {
            throw InvalidLoginPolicy::key($key, 'a list that names each value once');
        }
    }
}
