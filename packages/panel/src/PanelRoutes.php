<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Http\Inertia\InertiaRoutes;
use Cbox\Cms\Panel\Assets\AddonAssetController;
use Cbox\Cms\Panel\Assets\AssetController;
use Cbox\Cms\Panel\Assets\BrandController;
use Cbox\Cms\Panel\Assets\ThemeController;
use Cbox\Cms\Panel\Boundary\HandlePanelRequests;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandFile;
use Cbox\Cms\Panel\Domain\BundleHash;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Domain\Dto\PanelTheme;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Middleware\AuthenticatePanelSession;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Panel\Middleware\VerifyPanelCsrfToken;
use Cbox\Cms\Panel\Pages\AddonPageController;
use Cbox\Cms\Panel\Pages\ForgotPasswordController;
use Cbox\Cms\Panel\Pages\ForgotPasswordPageController;
use Cbox\Cms\Panel\Pages\HomeController;
use Cbox\Cms\Panel\Pages\LoginController;
use Cbox\Cms\Panel\Pages\LoginPageController;
use Cbox\Cms\Panel\Pages\LogoutController;
use Cbox\Cms\Panel\Pages\NotFoundController;
use Cbox\Cms\Panel\Pages\ResetPasswordController;
use Cbox\Cms\Panel\Pages\ResetPasswordPageController;
use Cbox\Cms\Panel\Reports\CspReportController;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Mounts the control panel (PRD 13.4) below a prefix, `cms` unless the application names another:
 *
 * - `GET <prefix>/build/{path}`, named ASSET: a file of the panel's build, such as its script.
 * - `GET <prefix>/theme/{version}.css` (PanelRoute::Theme): the stylesheet of the theme cms:build
 *   composed from the themes the installation selects (PRD 13.4).
 * - `GET <prefix>/brand/{name}` (PanelRoute::Brand): a file of the installation's brand, its logos
 *   and favicon, from cbox-cms.panel.branding.
 * - `GET <prefix>/addons/{addon}/{hash}/{path}` (PanelRoute::AddonAsset): a file of an addon's
 *   panel bundle, below the hash of the bundle as cms:build compiled it, checked against its
 *   SHA-384 before it is sent (AddonAssetResponse).
 * - `POST <prefix>/csp-report` (PanelRoute::CspReport): where a browser reports a violation of
 *   the panel's Content-Security-Policy, outside the CSRF check, because a browser sends no token
 *   with a report; it is counted by directive and addon and answered 204.
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
 *     person (PanelRoute::Home, Logout, Command), and `GET <prefix>/x/{namespace}/{path}`, a page of
 *     an addon, its PageContribution at the path, with its data query's result as its props
 *     (PanelRoute::AddonPage), or the page for a path the panel does not have when no addon has
 *     such a page or the person may not open it;
 *   - last, `GET <prefix>/{path?}` for any other path below the prefix, named NOT_FOUND: the
 *     panel's page for a path it does not have, with 404, which shows nothing of the installation
 *     and so needs no session.
 *
 * Only the routes whose PanelRoute allows it load the addons' panel UI (PRD 13.4): their action
 * carries ADDONS as true, and the root view writes the addons' entries, scopes, integrity and
 * stylesheets into those pages alone. A credential route, the page for an address the panel does
 * not have and every other route carry false, so no addon's code runs near a password or a reset
 * link; CredentialRoutesWithoutAddonsTest holds every credential route to it.
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

    /** The key of a route's action that says whether its page loads the addons' panel UI. */
    public const string ADDONS = 'cbox-cms.panel.addons';

    /** A file of an addon's bundle in its address: the form of a BundlePath, as a route takes it. */
    public const string ADDON_FILE = '[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9_][A-Za-z0-9._-]*)*';

    /** What an addon page's address takes as the addon's namespace: the form of an AddonNamespace. */
    public const string NAMESPACE_SEGMENT = '[a-z][a-z0-9]{0,19}';

    /** What an addon page's address takes as its path: the form of a PageContribution's path, slashes included. */
    public const string PAGE_PATH = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*';

    private function __construct() {}

    public static function register(Registrar $router, string $prefix = self::PREFIX): void
    {
        $router->group(['prefix' => trim($prefix, '/')], static function (Registrar $router): void {
            $router->get('build/{path}', AssetController::class)
                ->where('path', PanelBuild::FILE_PATTERN)
                ->name(self::ASSET);
            self::page($router->get('theme/{version}.css', ThemeController::class)
                ->where('version', PanelTheme::VERSION_PATTERN), PanelRoute::Theme);
            self::page($router->get('brand/{name}', BrandController::class)
                ->where('name', BrandFile::NAME_PATTERN), PanelRoute::Brand);
            self::page($router->get('addons/{addon}/{hash}/{path}', AddonAssetController::class)
                ->where(['addon' => '[a-z][a-z0-9]{0,19}', 'hash' => BundleHash::PATTERN, 'path' => self::ADDON_FILE]), PanelRoute::AddonAsset);
            self::page($router->post('csp-report', CspReportController::class)
                ->withoutMiddleware([PreventRequestForgery::class]), PanelRoute::CspReport);

            $router->group(['middleware' => [SendContentSecurityPolicy::class, HandlePanelRequests::class]], static function (Registrar $router): void {
                $router->group(['middleware' => [VerifyPanelCsrfToken::class]], static function (Registrar $router): void {
                    self::page($router->get('login', LoginPageController::class), PanelRoute::Login);
                    self::page($router->post('login', LoginController::class), PanelRoute::LoginSubmit);
                    self::page($router->get('forgot-password', ForgotPasswordPageController::class), PanelRoute::ForgotPassword);
                    self::page($router->post('forgot-password', ForgotPasswordController::class), PanelRoute::ForgotPasswordSubmit);
                    self::page($router->get('reset-password/{token}', ResetPasswordPageController::class)
                        ->where('token', self::TOKEN_SEGMENT), PanelRoute::ResetPassword);
                    self::page($router->post('reset-password', ResetPasswordController::class), PanelRoute::ResetPasswordSubmit);
                });

                $router->group(['middleware' => [AuthenticatePanelSession::class, VerifyPanelCsrfToken::class]], static function (Registrar $router): void {
                    self::page($router->get('', HomeController::class), PanelRoute::Home);
                    self::page($router->post('logout', LogoutController::class), PanelRoute::Logout);
                    self::withAddons(InertiaRoutes::register($router, PanelRoute::COMMANDS_PATH, PanelRoute::Command->value), PanelRoute::Command->allowsAddons());
                    self::page($router->get(PanelRoute::ADDON_PAGES_PATH.'/{namespace}/{path}', AddonPageController::class)
                        ->where(['namespace' => self::NAMESPACE_SEGMENT, 'path' => self::PAGE_PATH]), PanelRoute::AddonPage);
                });

                self::withAddons($router->get('{path?}', NotFoundController::class)
                    ->where('path', '.*')
                    ->name(self::NOT_FOUND), false);
            });
        });
    }

    /**
     * Whether the matched route's page loads the addons' panel UI; false for a request that
     * matched no panel route, and for one whose route does not say.
     */
    public static function allowsAddons(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && $route->getAction(self::ADDONS) === true;
    }

    private static function page(Route $route, PanelRoute $name): Route
    {
        return self::withAddons($route->name($name->value), $name->allowsAddons());
    }

    private static function withAddons(Route $route, bool $addons): Route
    {
        return $route->setAction([...$route->action, self::ADDONS => $addons]);
    }
}
