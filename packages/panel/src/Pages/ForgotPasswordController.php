<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Panel\Boundary\PasswordResetForms;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A request for a password reset link from its page (PRD 5.16): PasswordResetForms reads it,
 * RequestPasswordReset decides it and mails the link, and PasswordResetForms answers, the same for
 * every email. It holds no logic of its own.
 */
#[Internal]
final readonly class ForgotPasswordController
{
    public function __construct(
        private PasswordResetForms $forms,
        private RequestPasswordReset $resets,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->forms->requested($request, $this->resets->request($this->forms->request($request)));
    }
}
