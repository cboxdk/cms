<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use SensitiveParameter;

/**
 * A local login as a login form sent it (PRD 5.16), parsed by the form's Boundary: the login
 * identifier the person typed (TypedLogin), the password, or null when the field was left empty,
 * the IP address the request came from, or null when the Boundary could not read one, and the
 * session credential the browser still carried, or null, which the login ends, so a browser holds
 * one session at a time. var_dump() and a stack trace never show the identifier, the password or
 * the address.
 */
#[Internal]
final readonly class LocalLoginRequest
{
    public function __construct(
        #[SensitiveParameter] public TypedLogin $login,
        #[SensitiveParameter] public ?Password $password,
        #[SensitiveParameter] public ?ClientAddress $address,
        public ?TransportCredential $previous = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['login' => '[personal]', 'password' => '[hidden]', 'address' => '[personal]'];
    }
}
