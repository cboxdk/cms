<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetSubmission;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequest;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequestOutcome;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * The panel's two forms of a password reset (PRD 5.16): reads what they posted and answers how the
 * identity module's actions ended.
 *
 * The form that asks for a link posts EMAIL. Every request that was not empty goes back to its page
 * with REQUESTED flashed, whether a link was mailed or not; an empty email goes back with
 * validation_required under the field.
 *
 * The form of the reset page posts TOKEN, the token of the link the page was opened with, and
 * PASSWORD. A reset that logged the person in is answered by PanelSessions::start(); one that set
 * the password without a login goes to the login page with the reason password_changed; a refusal
 * goes back to the reset page with its catalog code in Inertia's errors prop, under PASSWORD when it
 * is about the password and under FORM otherwise.
 */
#[Internal]
final readonly class PasswordResetForms
{
    public const string EMAIL = 'email';

    public const string TOKEN = 'token';

    public const string PASSWORD = 'password';

    /** The errors prop's key of a refusal that is not about a field. */
    public const string FORM = 'form';

    /** The key of Laravel's session flashed after a request for a link was taken. */
    public const string REQUESTED = 'cbox-cms.panel.reset-requested';

    /** The path segment of the reset page for a token that is not in the form of one. */
    public const string NO_TOKEN = 'invalid';

    private const string TOKEN_SEGMENT = '/\A[a-z0-9_]{1,200}\z/';

    public function __construct(
        private PanelSessions $sessions,
        private UrlGenerator $urls,
    ) {}

    public function request(Request $request): ResetRequest
    {
        $email = $request->input(self::EMAIL);

        return new ResetRequest(is_string($email) ? $email : '', (string) $request->ip());
    }

    public function requested(Request $request, ResetRequestOutcome $outcome): Response
    {
        if ($outcome->taken) {
            $request->session()->flash(self::REQUESTED, true);
        } else {
            $this->flashErrors($request, [self::EMAIL => ErrorCode::ValidationRequired->value]);
        }

        return $this->redirect($this->urls->route(PanelRoute::ForgotPassword->value, [], false));
    }

    public function submission(Request $request): PasswordResetSubmission
    {
        $password = $request->input(self::PASSWORD);

        return new PasswordResetSubmission($this->token($request), is_string($password) ? $password : '', $this->sessions->carried($request));
    }

    public function reset(Request $request, PasswordResetOutcome $outcome): Response
    {
        if ($outcome->session instanceof NewSession) {
            return $this->sessions->start($request, $outcome->session);
        }

        if ($outcome->passwordSet) {
            return $this->sessions->passwordChanged($request);
        }

        $code = $outcome->refusal->value ?? '';
        $this->flashErrors($request, [$outcome->aboutPassword() ? self::PASSWORD : self::FORM => $code]);
        $token = $this->token($request);

        return $this->redirect($this->urls->route(PanelRoute::ResetPassword->value, [
            self::TOKEN => preg_match(self::TOKEN_SEGMENT, $token) === 1 ? $token : self::NO_TOKEN,
        ], false));
    }

    /**
     * Whether a request for a link was taken just before this request, as REQUESTED flashed it.
     */
    public function wasRequested(Request $request): bool
    {
        return $request->hasSession() && $request->session()->get(self::REQUESTED) === true;
    }

    private function token(Request $request): string
    {
        $token = $request->input(self::TOKEN);

        return is_string($token) ? $token : '';
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function flashErrors(Request $request, array $errors): void
    {
        $request->session()->flash('errors', new ViewErrorBag()->put('default', new MessageBag($errors)));
    }

    private function redirect(string $location): Response
    {
        return new Response('', PanelSessions::REDIRECT, ['Location' => $location]);
    }
}
