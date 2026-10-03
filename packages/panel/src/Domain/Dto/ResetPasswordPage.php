<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The props of the page a password reset link opens (PRD 5.16), Auth/ResetPassword in js/panel: the
 * address its form posts to, the addresses of the page that asks for a new link and of the login
 * page, the refusal of the reset just posted, and the token of the link, or null when the address
 * holds no text in the form of a token. Its JSON form is reset-password.v1.json in
 * packages/panel/resources/schemas/pages, written only by the generated ResetPasswordPageCodecV1
 * (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class ResetPasswordPage
{
    public function __construct(
        public string $action,
        public string $forgot,
        public string $login,
        public ResetPasswordRefusals $refusals,
        public ?string $token,
    ) {}
}
