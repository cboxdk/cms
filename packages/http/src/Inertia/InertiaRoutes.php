<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;

/**
 * The route of the Inertia profile (GUARDRAILS 2.1): `POST <prefix>/{command}/v{version}`, such as
 * POST commands/entry.create/v1, named NAME, for every write action the registry exposes on
 * Inertia. An application registers it inside the route group of its panel, which gives it the
 * web middleware, InertiaMiddleware and the panel's prefix.
 *
 * The body is a JSON object with the envelope fields under `envelope` (envelope.v1.json) and the
 * command's document under `command`; the credential is the request's Bearer token.
 */
#[Experimental]
final readonly class InertiaRoutes
{
    public const string NAME = 'cbox-cms.inertia.command';

    /** A command name, as #[Command] declares it. */
    public const string COMMAND = '[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+';

    /** A version: 1 or more, without leading zeros. */
    public const string VERSION = '[1-9][0-9]{0,8}';

    public static function register(Registrar $router, string $prefix = 'commands'): Route
    {
        return $router->post(trim($prefix, '/').'/{command}/v{version}', InertiaCommandController::class)
            ->where(['command' => self::COMMAND, 'version' => self::VERSION])
            ->name(self::NAME);
    }
}
