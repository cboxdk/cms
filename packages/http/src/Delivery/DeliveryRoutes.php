<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Delivery;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;

/**
 * The route of the delivery API's resolve (PRD 8.9, 8.10, MILESTONES M1 point 5):
 * `GET v1/resolve?site=<host>&locale=<locale>&path=<path>`, named NAME, with `debug=1` for the
 * explanation. An application registers it outside the web middleware group: the answer is the
 * same for every visitor, so no session may start and no cookie may be set on it (PRD 8.10 point
 * 2), and no `Vary: Cookie` is sent (point 6).
 */
#[Experimental]
final readonly class DeliveryRoutes
{
    public const string NAME = 'cbox-cms.delivery.resolve';

    public static function register(Registrar $router, string $path = 'v1/resolve'): Route
    {
        return $router->get(trim($path, '/'), ResolveController::class)->name(self::NAME);
    }
}
