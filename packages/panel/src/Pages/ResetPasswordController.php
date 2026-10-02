<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\PasswordReset\Actions\ResetPassword;
use Cbox\Cms\Panel\Boundary\PasswordResetForms;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A new password from the reset page (PRD 5.16): PasswordResetForms reads it, ResetPassword sets it,
 * ends the person's sessions and asks the login policy for a new one, and PasswordResetForms
 * answers. It holds no logic of its own.
 */
#[Internal]
final readonly class ResetPasswordController
{
    public function __construct(
        private PasswordResetForms $forms,
        private ResetPassword $resets,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->forms->reset($request, $this->resets->reset($this->forms->submission($request)));
    }
}
