<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Actions\LogInLocally;
use Cbox\Cms\Panel\Boundary\LoginForm;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A local login from the login form (PRD 5.16): LoginForm reads it, LogInLocally decides it and
 * issues the session, and LoginForm answers. It holds no logic of its own.
 */
#[Internal]
final readonly class LoginController
{
    public function __construct(
        private LoginForm $form,
        private LogInLocally $login,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->form->answer($request, $this->login->login($this->form->attempt($request)));
    }
}
