<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;

/**
 * A catalog code the panel's login page shows a refused login with (PRD 5.16): validation_required
 * for a field left empty, login_rejected for an unknown email, a wrong password and a login the
 * policy refused alike, and login_rate_limited.
 *
 * Each case's value is the code of its ErrorCode, which code() gives. The page's JSON Schema in
 * packages/panel/resources/schemas/pages lists the same values, so the page's generated TypeScript
 * type holds exactly these codes (GUARDRAILS 2.2).
 */
#[Internal]
enum LoginRefusal: string
{
    case RequiredField = 'validation_required';

    case Rejected = 'login_rejected';

    case RateLimited = 'login_rate_limited';

    public function code(): ErrorCode
    {
        return ErrorCode::from($this->value);
    }
}
