<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use SensitiveParameter;

/**
 * A request for a password reset link as the page's form sent it (PRD 5.16): the email the person
 * typed and the IP address the request came from. Neither is shown by var_dump() or in a stack
 * trace.
 */
#[Internal]
final readonly class ResetRequest
{
    public function __construct(
        #[SensitiveParameter] public string $identifier,
        #[SensitiveParameter] public string $ip,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['identifier' => '[personal]', 'ip' => '[personal]'];
    }
}
