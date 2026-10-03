<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The props of the page that asks for a password reset link (PRD 5.16), Auth/ForgotPassword in
 * js/panel: the address its form posts to, the login page's, for how many minutes a link works, the
 * refusal of the request just posted, and whether a request was just taken. Its JSON form is
 * forgot-password.v1.json in packages/panel/resources/schemas/pages, written only by the generated
 * ForgotPasswordPageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class ForgotPasswordPage
{
    public function __construct(
        public string $action,
        public string $login,
        public int $minutes,
        public ForgotPasswordRefusals $refusals,
        public bool $requested,
    ) {}
}
