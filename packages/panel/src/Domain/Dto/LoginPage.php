<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\SignInReason;

/**
 * The props of the panel's login page (PRD 5.16), Auth/Login in js/panel: the address its form
 * posts to, the address of the page that asks for a password reset link, why the panel sent the
 * browser there, and the refusal of the login just posted. Its JSON form is login.v1.json in
 * packages/panel/resources/schemas/pages, written only by the generated LoginPageCodecV1, whose
 * TypeScript the page imports (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class LoginPage
{
    public function __construct(
        public string $action,
        public string $forgot,
        public ?SignInReason $reason,
        public LoginRefusals $refusals,
    ) {}
}
