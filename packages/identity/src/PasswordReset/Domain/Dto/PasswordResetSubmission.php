<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use SensitiveParameter;

/**
 * A new password as the reset page's form sent it (PRD 5.16), parsed by the form's Boundary: the
 * token of the link, or null when what was posted is not in the form of a token or its checksum
 * does not match; the password the person typed, or null when the field was left empty; and the
 * session credential the browser still carried, or null, which the reset ends. The token and the
 * password are never shown by var_dump() or in a stack trace.
 */
#[Internal]
final readonly class PasswordResetSubmission
{
    public function __construct(
        #[SensitiveParameter] public ?PasswordResetToken $token,
        #[SensitiveParameter] public ?Password $password,
        public ?TransportCredential $previous = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[secret]', 'password' => '[hidden]'];
    }
}
