<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\TypedLogin;
use SensitiveParameter;

/**
 * A request for a password reset link as the page's form sent it (PRD 5.16), parsed by the form's
 * Boundary: the email the person typed (TypedLogin) and the IP address the request came from, or
 * null when the Boundary could not read one. Neither is shown by var_dump() or in a stack trace.
 */
#[Internal]
final readonly class ResetRequest
{
    public function __construct(
        #[SensitiveParameter] public TypedLogin $login,
        #[SensitiveParameter] public ?ClientAddress $address,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['login' => '[personal]', 'address' => '[personal]'];
    }
}
