<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Domain\SignInReason;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Inertia\ResponseFactory;
use LogicException;

/**
 * Renders the panel's pages that need no action (PRD 13.4, 5.16):
 *
 * - the page for a path the panel does not have, with the address of the panel's start, the
 *   prefix of the route that matched, so it can link back;
 * - the login page, with the address its form posts to, the address of the page that asks for a
 *   password reset link, and the reason the panel sent the browser there, a SignInReason the
 *   address names, or null;
 * - the page that asks for a password reset link, with the address its form posts to, the login
 *   page's, whether a request was just taken, and for how many minutes a link works;
 * - the page a reset link opens, with the address its form posts to, the token of the link, or
 *   null when the address holds no text in the form of a token, and the addresses of the other two
 *   pages. Its answer is never cached and sends no Referer, because its address holds the token;
 * - the start page of a person who logged in, with the address of the logout.
 */
#[Internal]
final readonly class PanelPages
{
    /** The Inertia page for a path the panel does not have, in js/panel/src/pages. */
    public const string NOT_FOUND = 'Errors/NotFound';

    /** The login page, in js/panel/src/pages. */
    public const string LOGIN = 'Auth/Login';

    /** The start page, in js/panel/src/pages. */
    public const string HOME = 'Home';

    /** The page that asks for a password reset link, in js/panel/src/pages. */
    public const string FORGOT_PASSWORD = 'Auth/ForgotPassword';

    /** The page a password reset link opens, in js/panel/src/pages. */
    public const string RESET_PASSWORD = 'Auth/ResetPassword';

    public function __construct(
        private ResponseFactory $inertia,
        private UrlGenerator $urls,
        private PasswordResetForms $resets,
    ) {}

    public function login(Request $request): Response|JsonResponse
    {
        $reason = $request->query(SignInReason::PARAMETER);

        return $this->render($request, self::LOGIN, [
            'action' => $this->urls->route(PanelRoute::LoginSubmit->value, [], false),
            'forgot' => $this->urls->route(PanelRoute::ForgotPassword->value, [], false),
            'reason' => is_string($reason) ? SignInReason::tryFrom($reason)?->value : null,
        ]);
    }

    public function forgotPassword(Request $request, ResetSettings $settings): Response|JsonResponse
    {
        return $this->render($request, self::FORGOT_PASSWORD, [
            'action' => $this->urls->route(PanelRoute::ForgotPasswordSubmit->value, [], false),
            'login' => $this->urls->route(PanelRoute::Login->value, [], false),
            'requested' => $this->resets->wasRequested($request),
            'minutes' => $settings->tokenMinutes,
        ]);
    }

    public function resetPassword(Request $request, string $token): Response|JsonResponse
    {
        $response = $this->render($request, self::RESET_PASSWORD, [
            'action' => $this->urls->route(PanelRoute::ResetPasswordSubmit->value, [], false),
            'token' => PasswordResetToken::parse($token)?->reveal(),
            'forgot' => $this->urls->route(PanelRoute::ForgotPassword->value, [], false),
            'login' => $this->urls->route(PanelRoute::Login->value, [], false),
        ]);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function home(Request $request): Response|JsonResponse
    {
        return $this->render($request, self::HOME, ['logout' => $this->urls->route(PanelRoute::Logout->value, [], false)]);
    }

    public function notFound(Request $request): Response|JsonResponse
    {
        $response = $this->render($request, self::NOT_FOUND, ['home' => $this->start($request)]);
        $response->setStatusCode(404);

        return $response;
    }

    /**
     * @param  array<string, string|int|bool|null>  $props
     */
    private function render(Request $request, string $page, array $props): Response|JsonResponse
    {
        $response = $this->inertia->render($page, $props)->toResponse($request);

        if (! $response instanceof Response && ! $response instanceof JsonResponse) {
            throw new LogicException('Inertia answered the panel page with a response of another kind.');
        }

        return $response;
    }

    /**
     * The panel's start: the root of the prefix the matched route was registered with.
     */
    private function start(Request $request): string
    {
        $route = $request->route();
        $prefix = $route instanceof Route ? $route->getPrefix() : null;

        return '/'.trim((string) $prefix, '/');
    }
}
