<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use SensitiveParameter;

/**
 * A new password as the reset page's form sent it (PRD 5.16): the token of the link, the password
 * the person typed and the session credential the browser still carried, or null, which the reset
 * ends. The token and the password are never shown by var_dump() or in a stack trace.
 */
#[Internal]
final readonly class PasswordResetSubmission
{
    public function __construct(
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] private string $password,
        public ?TransportCredential $previous = null,
    ) {}

    public function token(): string
    {
        return $this->token;
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[secret]', 'password' => '[hidden]'];
    }
}
