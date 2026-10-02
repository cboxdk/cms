<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginOutcome;
use Cbox\Cms\Identity\Login\Domain\LoginField;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * The panel's login form (PRD 5.16): reads what it posted, the fields EMAIL and PASSWORD, the
 * client's IP address and the session the browser still carries, into a LocalLoginRequest, and
 * answers how the login ended.
 *
 * A login that issued a session is answered by PanelSessions::start(). A refusal goes back to the
 * login page with the catalog code in Inertia's errors prop: under the form's field when the
 * refusal is about one, validation_required, and under FORM otherwise, login_rejected or
 * login_rate_limited. The page says the code in the person's language, one message for every
 * refused login, so it never tells whether an account exists.
 */
#[Internal]
final readonly class LoginForm
{
    public const string EMAIL = 'email';

    public const string PASSWORD = 'password';

    /** The errors prop's key of a refusal that is about the login as a whole. */
    public const string FORM = 'form';

    public function __construct(
        private PanelSessions $sessions,
        private UrlGenerator $urls,
    ) {}

    public function attempt(Request $request): LocalLoginRequest
    {
        $email = $request->input(self::EMAIL);
        $password = $request->input(self::PASSWORD);

        return new LocalLoginRequest(
            is_string($email) ? $email : '',
            is_string($password) ? $password : '',
            (string) $request->ip(),
            $this->sessions->carried($request),
        );
    }

    public function answer(Request $request, LoginOutcome $outcome): Response
    {
        if ($outcome->session instanceof NewSession) {
            return $this->sessions->start($request, $outcome->session);
        }

        $code = $outcome->refusal->value ?? '';
        $errors = $outcome->fields === []
            ? [self::FORM => $code]
            : array_fill_keys(array_map($this->field(...), $outcome->fields), $code);

        $request->session()->flash('errors', new ViewErrorBag()->put('default', new MessageBag($errors)));

        return new Response('', PanelSessions::REDIRECT, ['Location' => $this->urls->route(PanelRoute::Login->value, [], false)]);
    }

    private function field(LoginField $field): string
    {
        return match ($field) {
            LoginField::Identifier => self::EMAIL,
            LoginField::Password => self::PASSWORD,
        };
    }
}
