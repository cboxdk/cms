<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;

/**
 * A catalog code the page that asks for a password reset link shows a refused request with (PRD
 * 5.16): validation_required for an email left empty. Every other request gets the same answer.
 *
 * Each case's value is the code of its ErrorCode, which code() gives. The page's JSON Schema in
 * packages/panel/resources/schemas/pages lists the same values, so the page's generated TypeScript
 * type holds exactly these codes (GUARDRAILS 2.2).
 */
#[Internal]
enum ForgotPasswordRefusal: string
{
    case RequiredField = 'validation_required';

    public function code(): ErrorCode
    {
        return ErrorCode::from($this->value);
    }
}
