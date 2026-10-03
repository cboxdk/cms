<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;

/**
 * A catalog code the password reset page shows under the password field (PRD 5.16): an empty
 * password, and the refusals of the password policy.
 *
 * Each case's value is the code of its ErrorCode, which code() gives. The page's JSON Schema in
 * packages/panel/resources/schemas/pages lists the same values, so the page's generated TypeScript
 * type holds exactly these codes (GUARDRAILS 2.2).
 */
#[Internal]
enum ResetPasswordRefusal: string
{
    case RequiredField = 'validation_required';

    case TooShort = 'password_too_short';

    case TooLong = 'password_too_long';

    case Breached = 'password_breached';

    public function code(): ErrorCode
    {
        return ErrorCode::from($this->value);
    }
}
