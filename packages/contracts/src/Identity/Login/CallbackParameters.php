<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The parameters of an identity provider's callback, for a connection of the flow Redirect (PRD
 * 5.16): the query parameters whose value is text, by name, such as code, state, error and iss, as
 * the request gave them. The connection reads what its protocol defines and nothing else; in
 * particular it never reads a tenant from them.
 */
#[Experimental]
final readonly class CallbackParameters implements LoginResponse
{
    /**
     * @param  array<string, string>  $parameters
     */
    public function __construct(private array $parameters) {}

    public function flow(): LoginFlow
    {
        return LoginFlow::Redirect;
    }

    public function state(): string
    {
        return $this->parameters['state'] ?? '';
    }

    /**
     * The parameter's text, or null when the callback does not carry it.
     */
    public function parameter(string $name): ?string
    {
        return $this->parameters[$name] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function __debugInfo(): array
    {
        return ['parameters' => array_map(strval(...), array_keys($this->parameters))];
    }
}
