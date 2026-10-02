<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Panel\Assets\AssetController;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Middleware\HandlePanelRequests;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Panel\Pages\NotFoundController;
use Illuminate\Contracts\Routing\Registrar;

/**
 * Mounts the control panel (PRD 13.4) below a prefix, `cms` unless the application names another:
 *
 * - `GET <prefix>/build/{path}`, named ASSET: a file of the panel's build, such as its script.
 * - every panel page, behind SendContentSecurityPolicy, which gives the response a strict
 *   Content-Security-Policy with a nonce of its own (GUARDRAILS 6), and HandlePanelRequests,
 *   Inertia's middleware with the panel's root view and the build's version;
 * - last, `GET <prefix>/{path?}` for any other path below the prefix, named NOT_FOUND: the panel's
 *   page for a path it does not have, with 404.
 *
 * An application registers it inside its web middleware group, which gives the panel its session
 * and CSRF protection, such as in routes/web.php: PanelRoutes::register(app(Registrar::class)).
 */
#[Experimental]
final readonly class PanelRoutes
{
    public const string PREFIX = 'cms';

    public const string ASSET = 'cbox-cms.panel.asset';

    public const string NOT_FOUND = 'cbox-cms.panel.not-found';

    private function __construct() {}

    public static function register(Registrar $router, string $prefix = self::PREFIX): void
    {
        $router->group(['prefix' => trim($prefix, '/')], static function (Registrar $router): void {
            $router->get('build/{path}', AssetController::class)
                ->where('path', PanelBuild::FILE_PATTERN)
                ->name(self::ASSET);

            $router->group(['middleware' => [SendContentSecurityPolicy::class, HandlePanelRequests::class]], static function (Registrar $router): void {
                $router->get('{path?}', NotFoundController::class)
                    ->where('path', '.*')
                    ->name(self::NOT_FOUND);
            });
        });
    }
}
