<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Http\Inertia\InertiaRoutes;
use Cbox\Cms\Panel\Assets\AssetController;
use Cbox\Cms\Panel\Boundary\HandlePanelRequests;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Middleware\AuthenticatePanelSession;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Panel\Middleware\VerifyPanelCsrfToken;
use Cbox\Cms\Panel\Pages\ForgotPasswordController;
use Cbox\Cms\Panel\Pages\ForgotPasswordPageController;
use Cbox\Cms\Panel\Pages\HomeController;
use Cbox\Cms\Panel\Pages\LoginController;
use Cbox\Cms\Panel\Pages\LoginPageController;
use Cbox\Cms\Panel\Pages\LogoutController;
use Cbox\Cms\Panel\Pages\NotFoundController;
use Cbox\Cms\Panel\Pages\ResetPasswordController;
use Cbox\Cms\Panel\Pages\ResetPasswordPageController;
use Illuminate\Contracts\Routing\Registrar;

/**
 * Mounts the control panel (PRD 13.4) below a prefix, `cms` unless the application names another:
 *
 * - `GET <prefix>/build/{path}`, named ASSET: a file of the panel's build, such as its script.
 * - every panel page, behind SendContentSecurityPolicy, which gives the response a strict
 *   Content-Security-Policy with a nonce of its own (GUARDRAILS 6), and HandlePanelRequests,
 *   Inertia's middleware with the panel's root view and the build's version:
 *   - the login (PRD 5.16), behind VerifyPanelCsrfToken: `GET <prefix>/login`, the login page,
 *     and `POST <prefix>/login`, a local login from its form (PanelRoute::Login, LoginSubmit); and
 *     the password reset: `GET` and `POST <prefix>/forgot-password`, the page that asks for a link
 *     and its form, `GET <prefix>/reset-password/{token}`, the page the link opens, and
 *     `POST <prefix>/reset-password`, its form (ForgotPassword, ForgotPasswordSubmit,
 *     ResetPassword, ResetPasswordSubmit). The link in the mail points at the reset page at
 *     cbox-cms.identity.password_reset.url, which is app.url with /cms/reset-password by default;
 *     an application that mounts the panel at another prefix sets it;
 *   - the pages and actions of a person who logged in, behind AuthenticatePanelSession, which
 *     takes only a request whose session cookie verifies and sends any other to the login page,
 *     and VerifyPanelCsrfToken: `GET <prefix>`, the start page, `POST <prefix>/logout`, and the
 *     Inertia profile's `POST <prefix>/commands/{command}/v{version}`, which runs a command as the
 *     person (PanelRoute::Home, Logout, Command);
 *   - last, `GET <prefix>/{path?}` for any other path below the prefix, named NOT_FOUND: the
 *     panel's page for a path it does not have, with 404, which shows nothing of the installation
 *     and so needs no session.
 *
 * An application registers it inside its web middleware group, which gives the panel Laravel's
 * session, its cookies and its own CSRF protection, such as in routes/web.php:
 * PanelRoutes::register(app(Registrar::class)). Laravel's session should be in Valkey, as
 * docs/security/sessions.md says; the panel keeps its credential, the CMS session, apart from it.
 */
#[Experimental]
final readonly class PanelRoutes
{
    public const string PREFIX = 'cms';

    public const string ASSET = 'cbox-cms.panel.asset';

    public const string NOT_FOUND = 'cbox-cms.panel.not-found';

    /** What the reset page's address takes as its token: one path segment, which the page checks. */
    public const string TOKEN_SEGMENT = '[^/]{1,200}';

    private function __construct() {}

    public static function register(Registrar $router, string $prefix = self::PREFIX): void
    {
        $router->group(['prefix' => trim($prefix, '/')], static function (Registrar $router): void {
            $router->get('build/{path}', AssetController::class)
                ->where('path', PanelBuild::FILE_PATTERN)
                ->name(self::ASSET);

            $router->group(['middleware' => [SendContentSecurityPolicy::class, HandlePanelRequests::class]], static function (Registrar $router): void {
                $router->group(['middleware' => [VerifyPanelCsrfToken::class]], static function (Registrar $router): void {
                    $router->get('login', LoginPageController::class)->name(PanelRoute::Login->value);
                    $router->post('login', LoginController::class)->name(PanelRoute::LoginSubmit->value);
                    $router->get('forgot-password', ForgotPasswordPageController::class)->name(PanelRoute::ForgotPassword->value);
                    $router->post('forgot-password', ForgotPasswordController::class)->name(PanelRoute::ForgotPasswordSubmit->value);
                    $router->get('reset-password/{token}', ResetPasswordPageController::class)
                        ->where('token', self::TOKEN_SEGMENT)
                        ->name(PanelRoute::ResetPassword->value);
                    $router->post('reset-password', ResetPasswordController::class)->name(PanelRoute::ResetPasswordSubmit->value);
                });

                $router->group(['middleware' => [AuthenticatePanelSession::class, VerifyPanelCsrfToken::class]], static function (Registrar $router): void {
                    $router->get('', HomeController::class)->name(PanelRoute::Home->value);
                    $router->post('logout', LogoutController::class)->name(PanelRoute::Logout->value);
                    InertiaRoutes::register($router, 'commands', PanelRoute::Command->value);
                });

                $router->get('{path?}', NotFoundController::class)
                    ->where('path', '.*')
                    ->name(self::NOT_FOUND);
            });
        });
    }
}
