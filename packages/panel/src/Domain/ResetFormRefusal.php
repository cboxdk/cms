<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;

/**
 * A catalog code the password reset page shows a refused reset with that is not about the password
 * (PRD 5.16): password_reset_token_invalid for a link that is unknown, used or expired, and
 * breached_passwords_unavailable when the breach check could not be made.
 *
 * Each case's value is the code of its ErrorCode, which code() gives. The page's JSON Schema in
 * packages/panel/resources/schemas/pages lists the same values, so the page's generated TypeScript
 * type holds exactly these codes (GUARDRAILS 2.2).
 */
#[Internal]
enum ResetFormRefusal: string
{
    case TokenInvalid = 'password_reset_token_invalid';

    case CheckUnavailable = 'breached_passwords_unavailable';

    public function code(): ErrorCode
    {
        return ErrorCode::from($this->value);
    }
}
