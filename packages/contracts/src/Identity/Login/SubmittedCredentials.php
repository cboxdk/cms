<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * The credentials a login form took, for a connection of the flow Direct (PRD 5.16): the state the
 * form carried, the identifier the person typed, such as an email address, and the secret, such as
 * a password. The secret is never shown by var_dump() or in a stack trace; secret() reveals it to
 * the connection that checks it.
 */
#[Experimental]
final readonly class SubmittedCredentials implements LoginResponse
{
    public function __construct(
        private string $state,
        public string $identifier,
        #[SensitiveParameter] private string $secret,
    ) {}

    public function flow(): LoginFlow
    {
        return LoginFlow::Direct;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['identifier' => $this->identifier, 'secret' => '[hidden]', 'state' => '[hidden]'];
    }
}
