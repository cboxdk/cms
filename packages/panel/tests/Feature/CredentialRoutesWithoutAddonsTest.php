<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\PanelRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/*
 * No addon's panel UI runs near a credential (PRD 13.4, decision D13 of the panel extension
 * architecture): every credential route of PanelRoutes, where a person types a password, asks for
 * or follows a reset link or signs out, carries addons false in its action, and so does the page
 * for an address the panel does not have and every route that is no page; the root view then
 * writes no addon into those pages. Only the pages behind the login carry true.
 */

/**
 * The panel's routes in the workbench, by name.
 *
 * @return array<string, Route>
 */
function panelRoutesByName(): array
{
    $routes = [];

    foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if (is_string($name) && str_starts_with($name, 'cbox-cms.panel.')) {
            $routes[$name] = $route;
        }
    }

    return $routes;
}

it('holds every credential route to addons: false', function (): void {
    $routes = panelRoutesByName();
    $credential = [
        PanelRoute::Login,
        PanelRoute::LoginSubmit,
        PanelRoute::ForgotPassword,
        PanelRoute::ForgotPasswordSubmit,
        PanelRoute::ResetPassword,
        PanelRoute::ResetPasswordSubmit,
        PanelRoute::Logout,
    ];

    foreach ($credential as $route) {
        expect($route->allowsAddons())->toBeFalse($route->value)
            ->and($routes)->toHaveKey($route->value)
            ->and($routes[$route->value]->getAction(PanelRoutes::ADDONS))->toBeFalse($route->value);
    }
});

it('holds the page for an address the panel does not have and every route that is no page to addons: false', function (): void {
    $routes = panelRoutesByName();

    expect($routes[PanelRoutes::NOT_FOUND]->getAction(PanelRoutes::ADDONS))->toBeFalse();

    foreach ([PanelRoute::Theme, PanelRoute::Brand, PanelRoute::AddonAsset, PanelRoute::CspReport] as $route) {
        expect($route->allowsAddons())->toBeFalse($route->value)
            ->and($routes[$route->value]->getAction(PanelRoutes::ADDONS))->toBeFalse($route->value);
    }

    expect($routes[PanelRoutes::ASSET]->getAction(PanelRoutes::ADDONS))->toBeNull();
});

it('lets only the pages behind the login load addons, as their PanelRoute says', function (): void {
    $routes = panelRoutesByName();

    foreach (PanelRoute::cases() as $route) {
        expect($routes)->toHaveKey($route->value)
            ->and($routes[$route->value]->getAction(PanelRoutes::ADDONS))->toBe($route->allowsAddons(), $route->value);
    }

    expect(array_values(array_map(static fn (PanelRoute $route): string => $route->value, array_filter(PanelRoute::cases(), static fn (PanelRoute $route): bool => $route->allowsAddons()))))
        ->toBe([PanelRoute::Home->value, PanelRoute::Command->value]);
});

it('reads a request that matched no panel route, or a route that does not say, as one without addons', function (): void {
    $request = Request::create('/elsewhere');

    expect(PanelRoutes::allowsAddons($request))->toBeFalse();

    $request->setRouteResolver(static fn (): Route => new Route('GET', '/elsewhere', static fn (): string => ''));

    expect(PanelRoutes::allowsAddons($request))->toBeFalse();
});
