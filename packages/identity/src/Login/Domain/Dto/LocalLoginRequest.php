<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use SensitiveParameter;

/**
 * A local login as a login form sent it (PRD 5.16): the identifier and the password the person
 * typed, the IP address the request came from, and the session credential the browser still
 * carried, or null, which the login ends, so a browser holds one session at a time. The password is
 * never shown by var_dump() or in a stack trace.
 */
#[Internal]
final readonly class LocalLoginRequest
{
    public function __construct(
        public string $identifier,
        #[SensitiveParameter] private string $password,
        public string $ip,
        public ?TransportCredential $previous = null,
    ) {}

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['identifier' => '[personal]', 'password' => '[hidden]', 'ip' => '[personal]'];
    }
}
