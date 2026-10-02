<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;

/**
 * The routes of the Inertia profile (GUARDRAILS 2.1), which an application registers inside the
 * route group of its panel, which gives them the web middleware, InertiaMiddleware and the panel's
 * prefix; the credential is the session the panel authenticated, or else the request's Bearer
 * token (RequestCredential).
 *
 * register() adds `POST <prefix>/{command}/v{version}`, such as POST commands/entry.create/v1,
 * named NAME unless the caller names it otherwise, as the panel does for its own, for every write
 * action the registry exposes on Inertia. The body is a JSON object with the envelope fields under
 * `envelope` (envelope.v1.json) and the command's document under `command`.
 *
 * queries() adds `GET <prefix>/{query}/v{version}`, such as GET queries/role.list/v1, named
 * QUERY_NAME, for every query action the registry exposes on Inertia: a page visit that renders the
 * page component given, with the query's document in the query parameter `query`, as REST reads it,
 * and the result, or the problem, in the page's props.
 */
#[Experimental]
final readonly class InertiaRoutes
{
    public const string NAME = 'cbox-cms.inertia.command';

    public const string QUERY_NAME = 'cbox-cms.inertia.query';

    /** The route default that names the page component a read renders. */
    public const string COMPONENT = 'cbox-cms.inertia.component';

    /** A command name, as #[Command] declares it. */
    public const string COMMAND = '[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+';

    /** A version: 1 or more, without leading zeros. */
    public const string VERSION = '[1-9][0-9]{0,8}';

    public static function register(Registrar $router, string $prefix = 'commands', string $name = self::NAME): Route
    {
        return $router->post(trim($prefix, '/').'/{command}/v{version}', InertiaCommandController::class)
            ->where(['command' => self::COMMAND, 'version' => self::VERSION])
            ->name($name);
    }

    /**
     * @param  string  $component  the Inertia page component every read through the route renders
     */
    public static function queries(Registrar $router, string $component, string $prefix = 'queries'): Route
    {
        return $router->get(trim($prefix, '/').'/{query}/v{version}', InertiaQueryController::class)
            ->where(['query' => self::COMMAND, 'version' => self::VERSION])
            ->defaults(self::COMPONENT, $component)
            ->name(self::QUERY_NAME);
    }
}
